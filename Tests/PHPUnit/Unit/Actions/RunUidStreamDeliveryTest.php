<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Actions;

use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\AbstractHttpFacade\IteratorStream;
use PHPUnit\Framework\TestCase;

/**
 * Pins the one place RunTestRunUidTest cannot see: the HTTP stream that carries an action's output to the web
 * console.
 *
 * WHY THIS EXISTS: RunTestRunUidTest drains the action's generator directly and proves the UID line is yielded
 * before the failing step. The web console does not drain it that way - IteratorStream::read() collected the
 * chunks into a local string and let the exception escape, so every chunk yielded before the failure,
 * including the UID line, was thrown away together with it. Only the exception (which carries the UID) arrived.
 */
class RunUidStreamDeliveryTest extends TestCase
{
    /**
     * An error after output must not swallow the output, and must not be swallowed itself.
     */
    public function testOutputYieldedBeforeAnErrorIsDeliveredBeforeTheError(): void
    {
        $stream = new IteratorStream($this->failingGenerator());

        $chunks = [];
        $error = null;
        try {
            while (! $stream->eof()) {
                $chunks[] = $stream->read(8192);
            }
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertSame("banner\nRun UID: 0xabc\n", implode('', $chunks));
        $this->assertInstanceOf(RuntimeException::class, $error, 'The error must still reach the reader');
        $this->assertSame('boom', $error->getMessage());
    }

    /**
     * Without any earlier output the error is thrown at once, as before.
     */
    public function testErrorWithoutOutputIsThrownImmediately(): void
    {
        $stream = new IteratorStream((function () {
            throw new RuntimeException('early');
            yield '';
        })());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('early');
        $stream->read(8192);
    }

    /**
     * A held-back error is delivered once; the stream is finished afterwards.
     */
    public function testHeldBackErrorIsThrownOnlyOnce(): void
    {
        $stream = new IteratorStream($this->failingGenerator());
        $stream->read(8192);

        try {
            $stream->read(8192);
            $this->fail('The held-back error must be thrown by the next read');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->read(8192));
    }

    private function failingGenerator(): \Generator
    {
        yield "banner\n";
        yield "Run UID: 0xabc\n";
        throw new RuntimeException('boom');
    }
}
