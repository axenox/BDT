<?php
namespace axenox\BDT\Tests\PHPUnit;

use axenox\BDT\Common\BdtRun;
use axenox\BDT\Common\Selectors\BdtRunSelector;
use axenox\BDT\Facades\MarkdownPrinters\BdtRunMarkdownPrinter;
use exface\Core\CommonLogic\AppInstallers\ComponentRegistryInstaller;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\CommonLogic\Selectors\FacadeSelector;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Facades\AbstractHttpFacade\NotFoundHandler;
use exface\Core\Facades\DocsFacade;
use exface\Core\Facades\DocsFacade\Middleware\ComponentPrinterMiddleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use kabachello\FileRoute\FileReaders\MarkdownReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Filesystem\Filesystem;

class BdtMarkdownPrinterMiddlewareTest extends TestCase
{
    private ComponentPrinterMiddleware $middleware;
    private string $directory;
    private Workbench $workbench;

    /**
     * Creates the real middleware without starting the workbench or accessing test-run data.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $workbench = $this->getMockBuilder(Workbench::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['filemanager'])
            ->getMock();
        $this->workbench = $workbench;
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bdt-component-printer-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory . DIRECTORY_SEPARATOR . 'config');
        $filemanager = new Filemanager($workbench);
        $workbench->method('filemanager')->willReturn($filemanager);
        $workbench->__construct([
            'SERVER.INSTALLATION_NAME' => 'bdt-component-printer-test',
            'MONITOR.ENABLED' => false,
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
        (new \ReflectionProperty(Filemanager::class, 'path_to_config_folder'))->setValue(
            $filemanager,
            $this->directory . DIRECTORY_SEPARATOR . 'config'
        );
        $installer = new ComponentRegistryInstaller($workbench->getApp('axenox.BDT')->getSelector());
        iterator_to_array($installer->install(dirname(__DIR__, 2)));
        $facade = new DocsFacade(new FacadeSelector($workbench, DocsFacade::class));
        $this->middleware = new ComponentPrinterMiddleware(
            $facade,
            'https://example.test/exface',
            'exface/core/Docs/understanding_the_metamodel/Model_components.md',
            new MarkdownReader()
        );
    }

    /**
     * Removes only the isolated registry installation created by this test.
     */
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    /**
     * Uses the Core facade for template lookup instead of the BDT app directory.
     */
    public function testUsesCoreRenderingContext(): void
    {
        $getFacade = new \ReflectionMethod($this->middleware, 'getFacade');
        self::assertSame(DocsFacade::class, get_class($getFacade->invoke($this->middleware)));
        self::assertInstanceOf(ComponentPrinterMiddleware::class, $this->middleware);
    }

    /**
     * Renders the registered feature through Core's public component endpoint.
     */
    public function testRendersRawMarkdown(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/exface/' . $this->buildComponentUrl(
            'bdt_feature',
            ' axenox/bdt/Tests/Behat/Features/Administration/login.feature '
        ), [
            'Accept' => 'text/markdown'
        ]);
        $response = $this->middleware->process($request, new NotFoundHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/markdown', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('```gherkin', (string) $response->getBody());
        self::assertStringContainsString('Feature: Login functionality', (string) $response->getBody());
    }

    /**
     * Registers both app-owned types idempotently and removes only those types on uninstall.
     */
    public function testInstallsAndUninstallsBdtComponents(): void
    {
        $registry = $this->workbench->getComponentRegistry();
        $installer = new ComponentRegistryInstaller($this->workbench->getApp('axenox.BDT')->getSelector());
        $path = $this->directory . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ComponentRegistry.config.json';
        $before = file_get_contents($path);
        iterator_to_array($installer->install(dirname(__DIR__, 2)));
        self::assertSame($before, file_get_contents($path));
        $installed = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['bdt_run', 'bdt_feature'], $installed['SOURCES']['axenox.BDT']);
        self::assertSame(BdtRunMarkdownPrinter::class, ltrim(
            $registry->getComponentProperty('bdt_run', 'documentation')['markdown_printer_class'],
            '\\'
        ));
        iterator_to_array($installer->uninstall());
        self::assertNotContains('bdt_run', $registry->getComponentKeys());
        self::assertNotContains('bdt_feature', $registry->getComponentKeys());
        self::assertContains('object', $registry->getComponentKeys());
    }

    /**
     * Keeps the run component independent from the feature-file component.
     */
    public function testConstructsRunPrinterFromRunComponent(): void
    {
        $selector = new BdtRunSelector($this->workbench, '0x11f1bc127645094cbc12025041000001');
        self::assertSame('bdt_run', $selector->getComponentType());
        self::assertInstanceOf(
            BdtRunMarkdownPrinter::class,
            BdtRunMarkdownPrinter::constructForInstance(BdtRun::createFromSelector($selector))
        );
        $this->expectException(\InvalidArgumentException::class);
        BdtRunMarkdownPrinter::constructForInstance(new \stdClass());
    }

    /**
     * Preserves literal percent sequences and plus signs when supplying the component parameter.
     */
    public function testPreservesEncodedSelector(): void
    {
        $selector = 'axenox/bdt/Tests/missing%2Fname+suffix.feature';
        $request = new ServerRequest('GET', 'https://example.test/exface/' . $this->buildComponentUrl('bdt_feature', $selector));
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage($selector);
        $this->middleware->process($request, new NotFoundHandler());
    }

    /**
     * Uses Core's HTML template and DocsFacade asset base without duplicating rendering.
     */
    public function testRendersHtmlWithCoreAssetBase(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/exface/' . $this->buildComponentUrl(
            'bdt_feature',
            'axenox/bdt/Tests/Behat/Features/Administration/login.feature'
        ));
        $errorHandler = set_error_handler(static fn() => false);
        restore_error_handler();
        $exceptionHandler = set_exception_handler(static fn(\Throwable $error) => throw $error);
        restore_exception_handler();
        try {
            $response = $this->middleware->process($request, new NotFoundHandler());
        } finally {
            // HTML rendering starts the workbench, which installs its own process handlers.
            if (set_error_handler($errorHandler) !== $errorHandler) {
                restore_error_handler();
            }
            restore_error_handler();
            if (set_exception_handler($exceptionHandler) !== $exceptionHandler) {
                restore_exception_handler();
            }
            restore_exception_handler();
        }
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('language-gherkin', $html);
        self::assertStringContainsString('https://example.test/exface/api/docs/', $html);
    }

    /**
     * Rejects invalid selectors through Core validation and the registered BDT selector.
     */
    #[DataProvider('invalidSelectors')]
    public function testRejectsInvalidSelectors(array $params, string $exceptionClass): void
    {
        $request = new ServerRequest('GET', 'https://example.test/exface/api/docs/exface/core/Docs/understanding_the_metamodel/Model_components.md?' . http_build_query(
            array_merge(['component' => 'bdt_feature'], $params)
        ));
        $this->expectException($exceptionClass);
        $this->middleware->process($request, new NotFoundHandler());
    }

    /**
     * Provides missing, malformed, unsafe and nonexistent feature selectors.
     */
    public static function invalidSelectors(): array
    {
        return [
            [[], \InvalidArgumentException::class],
            [['selector' => []], \InvalidArgumentException::class],
            [['selector' => ' '], \InvalidArgumentException::class],
            [['selector' => str_repeat('a', 4097)], \InvalidArgumentException::class],
            [['selector' => '../secret.feature'], \InvalidArgumentException::class],
            [['selector' => 'axenox/bdt/test.feature' . "\0"], \InvalidArgumentException::class],
            [['selector' => 'axenox/bdt/Tests/nonexistent-report-test.feature'], \OutOfBoundsException::class]
        ];
    }

    /**
     * Builds the currently registered Core DocsFacade route without relying on Core's stale URL helper.
     */
    private function buildComponentUrl(string $component, string $selector): string
    {
        return 'api/docs/exface/core/Docs/understanding_the_metamodel/Model_components.md?'
            . http_build_query(['component' => $component, 'selector' => $selector], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Leaves unrelated routes and their request parameters untouched.
     */
    public function testPassesUnrelatedRequestsToHandler(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/exface/api/other');
        $expected = new Response(202, [], 'next handler');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expected);

        self::assertSame($expected, $this->middleware->process($request, $handler));
    }
}
