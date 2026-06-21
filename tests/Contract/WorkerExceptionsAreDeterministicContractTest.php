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

namespace Coretsia\Platform\Worker\Tests\Contract;

use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerException;
use Coretsia\Platform\Worker\Exception\WorkerForkFailedException;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerExceptionsAreDeterministicContractTest extends TestCase
{
    #[DataProvider('provideWorkerExceptions')]
    public function testConcreteWorkerExceptionsExposeDeterministicPublicShape(
        WorkerException $exception,
        string $expectedErrorCode,
        string $expectedReason,
    ): void {
        self::assertSame($expectedErrorCode, $exception->errorCode());
        self::assertSame($expectedReason, $exception->reason());
        self::assertSame($expectedErrorCode . ': ' . $expectedReason, $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[DataProvider('provideWorkerExceptions')]
    public function testConcreteWorkerExceptionMessagesDoNotExposeUnsafeRuntimeData(
        WorkerException $exception,
        string $_expectedErrorCode,
        string $_expectedReason,
    ): void {
        $message = $exception->getMessage();

        foreach (self::forbiddenPublicMessageFragments() as $fragment) {
            self::assertStringNotContainsString(
                $fragment,
                $message,
                'Worker exception public message must not expose unsafe fragment: ' . $fragment,
            );
        }

        self::assertNoUnsafePathOrEndpointPattern($message);
    }

    /**
     * @return iterable<string, array{WorkerException, string, string}>
     */
    public static function provideWorkerExceptions(): iterable
    {
        yield 'start failed' => [
            WorkerStartFailedException::startFailed(),
            WorkerStartFailedException::ERROR_CODE,
            WorkerStartFailedException::REASON_START_FAILED,
        ];

        yield 'invalid state' => [
            WorkerStartFailedException::invalidState(),
            WorkerStartFailedException::ERROR_CODE,
            WorkerStartFailedException::REASON_INVALID_STATE,
        ];

        yield 'request handler missing' => [
            WorkerStartFailedException::requestHandlerMissing(),
            WorkerStartFailedException::ERROR_CODE,
            WorkerStartFailedException::REASON_REQUEST_HANDLER_MISSING,
        ];

        yield 'request handler unresolvable' => [
            WorkerStartFailedException::requestHandlerUnresolvable(),
            WorkerStartFailedException::ERROR_CODE,
            WorkerStartFailedException::REASON_REQUEST_HANDLER_UNRESOLVABLE,
        ];

        yield 'request handler invalid' => [
            WorkerStartFailedException::requestHandlerInvalid(),
            WorkerStartFailedException::ERROR_CODE,
            WorkerStartFailedException::REASON_REQUEST_HANDLER_INVALID,
        ];

        yield 'fork failed' => [
            WorkerForkFailedException::forkFailed(),
            WorkerForkFailedException::ERROR_CODE,
            WorkerForkFailedException::REASON_FORK_FAILED,
        ];

        yield 'communication failed' => [
            WorkerCommunicationFailedException::communicationFailed(),
            WorkerCommunicationFailedException::ERROR_CODE,
            WorkerCommunicationFailedException::REASON_COMMUNICATION_FAILED,
        ];

        yield 'not running' => [
            WorkerNotRunningException::notRunning(),
            WorkerNotRunningException::ERROR_CODE,
            WorkerNotRunningException::REASON_NOT_RUNNING,
        ];
    }

    /**
     * @return list<string>
     */
    private static function forbiddenPublicMessageFragments(): array
    {
        return [
            '/home/coretsia/app',
            '/Users/coretsia/app',
            'C:\\coretsia\\app',
            'C:/coretsia/app',
            '\\\\server\\share',
            'var/tmp/worker.sock',
            'private-worker-control.sock',
            'tcp:127.0.0.1:9327',
            'tcp://127.0.0.1:9327',
            '127.0.0.1:9327',
            'payload-fragment',
            '{"payload":"secret"}',
            'Authorization',
            'Bearer secret-token',
            'X-Internal-Token',
            'Cookie:',
            'PHPSESSID',
            'previous throwable leaked',
            'SQLSTATE',
            'stack trace',
        ];
    }

    private static function assertNoUnsafePathOrEndpointPattern(string $message): void
    {
        foreach (
            [
                'unix-home' => '#/home/#',
                'mac-users' => '#/Users/#',
                'windows-drive' => '#\b[A-Z]:(?:\\\\+|/)#i',
                'windows-unc' => '#\\\\{2,}server\\\\+share#i',
                'tcp-endpoint' => '#\btcp://[^\s]+#i',
                'host-port-endpoint' => '#\b(?:127\.0\.0\.1|localhost|0\.0\.0\.0):[0-9]{2,5}\b#i',
            ] as $label => $pattern
        ) {
            $matched = \preg_match($pattern, $message);

            self::assertNotFalse($matched, 'Invalid worker exception safety regex: ' . $label);
            self::assertSame(
                0,
                $matched,
                'Worker exception public message must not expose unsafe pattern: ' . $label,
            );
        }
    }
}
