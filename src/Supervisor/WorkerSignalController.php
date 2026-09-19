<?php

declare(strict_types=1);

/*
 * Coretsia Framework (Monorepo)
 *
 * Project: Coretsia Framework (Monorepo)
 * Authors: Vladyslav Mudrichenko and contributors
 * Copyright (c) 2026 Vladyslav Mudrichenko
 *
 * SPDX-FileCopyrightText: 2026 Vladyslav Mudrichenko
 * SPDX-License-Identifier: Apache-2.0
 *
 * For contributors list, see git history.
 * See LICENSE and NOTICE in the project root for full license information.
 */

namespace Coretsia\Platform\Worker\Supervisor;

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;

/**
 * Converts supported process signals into supervisor shutdown intent.
 *
 * The parent supervisor exclusively owns SIGTERM, SIGINT, and SIGCHLD while
 * running. Signal handlers record intent only; child termination and reaping
 * stay in the synchronous supervisor loop.
 */
final class WorkerSignalController
{
    private const string MODE_PCNTL = 'pcntl';
    private const string MODE_WINDOWS = 'windows';

    /** @var array<int, true> */
    private array $shutdownSignals = [];

    private bool $childExitSignalPending = false;

    private ?string $installedMode = null;
    private bool $previousAsyncSignals = false;
    private mixed $previousSigtermHandler = null;
    private mixed $previousSigintHandler = null;
    private mixed $previousSigchldHandler = null;
    private ?\Closure $windowsHandler = null;

    public function __construct(
        private readonly string $platformFamily = \PHP_OS_FAMILY,
    ) {
        if (
            $platformFamily === ''
            || \preg_match('/[\x00-\x1F\x7F]/', $platformFamily) === 1
        ) {
            throw new \InvalidArgumentException('worker-signal-platform-invalid');
        }
    }

    public function install(): void
    {
        if ($this->installedMode !== null) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        $this->shutdownSignals = [];
        $this->childExitSignalPending = false;

        if (\strcasecmp($this->platformFamily, 'Windows') === 0) {
            $this->installWindowsHandler();

            return;
        }

        $this->installPcntlHandlers();
    }

    /**
     * Reads shutdown intent that may be mutated asynchronously by installed
     * operating-system signal handlers between consecutive method calls.
     *
     * @phpstan-impure
     */
    // @phpstan-ignore impureMethod.pure (return value depends on asynchronously mutated signal-handler state)
    public function shutdownRequested(): bool
    {
        return $this->shutdownSignals !== [];
    }

    public function wasShutdownSignal(int $signal): bool
    {
        return $signal > 0 && isset($this->shutdownSignals[$signal]);
    }

    /**
     * Consumes the transient SIGCHLD interruption marker.
     *
     * The marker is used only to distinguish an interrupted control-channel
     * select from a real transport failure. Child reaping remains owned by the
     * synchronous supervisor loop.
     */
    public function consumeChildExitSignal(): bool
    {
        $pending = $this->childExitSignalPending;
        $this->childExitSignalPending = false;

        return $pending;
    }

    public function uninstall(): void
    {
        if ($this->installedMode === self::MODE_PCNTL) {
            $this->restorePcntlHandlers();
        } elseif (
            $this->installedMode === self::MODE_WINDOWS
        ) {
            $this->removeWindowsHandler();
        }

        $this->clearRuntimeState();
    }


    private function installPcntlHandlers(): void
    {
        if (
            !\function_exists('pcntl_signal')
            || !\function_exists('pcntl_signal_get_handler')
            || !\function_exists('pcntl_async_signals')
            || !\defined('SIGTERM')
            || !\defined('SIGINT')
            || !\defined('SIGCHLD')
            || !\defined('SIG_DFL')
        ) {
            throw WorkerStartFailedException::signalHandlingUnavailable();
        }

        try {
            $this->previousAsyncSignals = \pcntl_async_signals();
            $this->previousSigtermHandler = \pcntl_signal_get_handler(\SIGTERM);
            $this->previousSigintHandler = \pcntl_signal_get_handler(\SIGINT);
            $this->previousSigchldHandler = \pcntl_signal_get_handler(\SIGCHLD);

            \pcntl_async_signals(true);

            $shutdownHandler = function (int $signal): void {
                $this->shutdownSignals[$signal] = true;
            };

            $childExitHandler = function (): void {
                $this->childExitSignalPending = true;
            };

            if (
                !\pcntl_signal(
                    \SIGTERM,
                    $shutdownHandler,
                    true,
                )
                || !\pcntl_signal(
                    \SIGINT,
                    $shutdownHandler,
                    true,
                )
                || !\pcntl_signal(
                    \SIGCHLD,
                    $childExitHandler,
                    true,
                )
            ) {
                throw WorkerStartFailedException::signalHandlingUnavailable();
            }

            $this->installedMode = self::MODE_PCNTL;
        } catch (WorkerStartFailedException $exception) {
            $this->restorePcntlHandlers();

            throw $exception;
        } catch (\Throwable) {
            $this->restorePcntlHandlers();

            throw WorkerStartFailedException::signalHandlingUnavailable();
        }
    }

    private function restorePcntlHandlers(): void
    {
        if (
            \function_exists('pcntl_signal')
            && \defined('SIGTERM')
            && \defined('SIGINT')
            && \defined('SIGCHLD')
            && \defined('SIG_DFL')
        ) {
            @\pcntl_signal(
                \SIGTERM,
                $this->previousSigtermHandler ?? \SIG_DFL,
                true,
            );

            @\pcntl_signal(
                \SIGINT,
                $this->previousSigintHandler ?? \SIG_DFL,
                true,
            );

            @\pcntl_signal(
                \SIGCHLD,
                $this->previousSigchldHandler ?? \SIG_DFL,
                true,
            );
        }

        if (\function_exists('pcntl_async_signals')) {
            @\pcntl_async_signals($this->previousAsyncSignals);
        }

        $this->clearPreviousPcntlState();
    }


    private function installWindowsHandler(): void
    {
        if (
            !\function_exists('sapi_windows_set_ctrl_handler')
            || !\defined('PHP_WINDOWS_EVENT_CTRL_C')
            || !\defined('PHP_WINDOWS_EVENT_CTRL_BREAK')
        ) {
            throw WorkerStartFailedException::signalHandlingUnavailable();
        }

        $handler = function (int $event): void {
            if (
                $event === \PHP_WINDOWS_EVENT_CTRL_C
                || $event === \PHP_WINDOWS_EVENT_CTRL_BREAK
            ) {
                $this->shutdownSignals[$event] = true;
            }
        };

        if (
            !@\sapi_windows_set_ctrl_handler(
                $handler,
                true,
            )
        ) {
            throw WorkerStartFailedException::signalHandlingUnavailable();
        }

        $this->windowsHandler = $handler;
        $this->installedMode = self::MODE_WINDOWS;
    }

    private function removeWindowsHandler(): void
    {
        if ($this->windowsHandler instanceof \Closure && \function_exists('sapi_windows_set_ctrl_handler')) {
            @\sapi_windows_set_ctrl_handler(
                $this->windowsHandler,
                false,
            );
        }

        $this->windowsHandler = null;
    }

    private function clearRuntimeState(): void
    {
        $this->installedMode = null;
        $this->shutdownSignals = [];
        $this->childExitSignalPending = false;
        $this->windowsHandler = null;

        $this->clearPreviousPcntlState();
    }

    private function clearPreviousPcntlState(): void
    {
        $this->previousAsyncSignals = false;
        $this->previousSigtermHandler = null;
        $this->previousSigintHandler = null;
        $this->previousSigchldHandler = null;
    }
}
