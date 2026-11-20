<?php
namespace axenox\BDT\Tests\PHPUnit;

use axenox\BDT\Facades\MarkdownPrinters\BdtRunMarkdownPrinter;
use axenox\BDT\Facades\MarkdownPrinters\AbstractBdtMarkdownPrinter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\CommonLogic\Model\Model;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\TranslationInterface;
use exface\Core\Facades\HttpFileServerFacade;
use exface\Core\DataTypes\BinaryDataType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BdtRunMarkdownPrinterTest extends TestCase
{
    /**
     * Verifies that a Gherkin tag is matched as a complete comma-separated token.
     *
     * @return void
     */
    public function testMatchesExactScenarioTags(): void
    {
        $printer = (new \ReflectionClass(BdtRunMarkdownPrinter::class))->newInstanceWithoutConstructor();
        $hasExactTag = new \ReflectionMethod(BdtRunMarkdownPrinter::class, 'hasExactTag');

        $this->assertTrue($hasExactTag->invoke($printer, '@smoke, @login', '@smoke'));
        $this->assertFalse($hasExactTag->invoke($printer, '@smoke_test, @login', '@smoke'));
    }

    /**
     * Verifies optional @ prefix handling and rejects multiple or malformed tags.
     *
     * @return void
     */
    public function testNormalizesAndValidatesScenarioTags(): void
    {
        $printer = (new \ReflectionClass(BdtRunMarkdownPrinter::class))->newInstanceWithoutConstructor();
        $normalizeTag = new \ReflectionMethod(BdtRunMarkdownPrinter::class, 'normalizeTag');

        $this->assertSame('@smoke', $normalizeTag->invoke($printer, 'smoke'));
        $this->assertSame('@smoke', $normalizeTag->invoke($printer, '@smoke'));

        $this->expectException(\InvalidArgumentException::class);
        $normalizeTag->invoke($printer, '@smoke @login');
    }

    /**
     * Verifies that run details, nested steps, and escaped test output are rendered.
     *
     * @return void
     */
    #[DataProvider('reportLanguages')]
    public function testRendersRunAndNestedStepDetailsAsMarkdown(string $language, string $headings, string $passed, string $failed): void
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $workbench->method('getUrl')->willReturn('http://localhost/exface/exface/');
        $workbench->method('isStarted')->willReturn(true);
        $dictionary = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '\\Translations\\axenox.BDT.' . $language . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $translator = $this->createMock(TranslationInterface::class);
        $translator->method('translate')->willReturnCallback(static function (string $key) use ($dictionary): string {
            return $dictionary[$key];
        });
        $app = $this->createMock(AppInterface::class);
        $app->method('getTranslator')->willReturn($translator);
        $fileFacade = $this->createMock(HttpFileServerFacade::class);
        $fileFacade->method('getUrlRouteDefault')->willReturn('api/files');
        $fileFacade->method('getWorkbench')->willReturn($workbench);
        $app->method('get')->willReturn($fileFacade);
        $workbench->method('getApp')->willReturn($app);
        $model = $this->createMock(Model::class);
        $workbench->method('model')->willReturn($model);
        $object = $this->createMock(MetaObjectInterface::class);
        $object->method('getName')->willReturn('Model test run');
        $object->method('getWorkbench')->willReturn($workbench);
        $object->method('getAliasWithNamespace')->willReturn('axenox.BDT.run_step_screenshot');
        $object->method('getAttribute')->willReturnCallback(function (string $alias): MetaAttributeInterface {
            $attribute = $this->createMock(MetaAttributeInterface::class);
            $attribute->method('getName')->willReturn('Model ' . $alias);
            return $attribute;
        });
        $model->method('getObject')->willReturn($object);
        $printer = new BdtRunMarkdownPrinter($workbench, '0x11f1bc127645094cbc12025041000001');
        $renderRun = new \ReflectionMethod(BdtRunMarkdownPrinter::class, 'renderRun');
        $markdown = $renderRun->invoke(
            $printer,
            [
                'UID' => '0xrun',
                'behat_command' => 'behat --tags=@smoke',
                'started_on' => '2026-11-10 10:00:00',
                'finished_on' => '2026-11-10 10:00:03',
                'duration_time' => '3 seconds',
                'run_stats__status' => 'failed',
                'run_stats__features_total' => 1,
                'run_stats__features_passed' => 0,
                'run_stats__features_failed' => 1,
                'run_stats__scenarios_total' => 1,
                'run_stats__scenarios_passed' => 0,
                'run_stats__scenarios_failed' => 1,
                'run_stats__steps_total' => 2,
                'run_stats__steps_passed' => 1,
                'run_stats__steps_failed' => 1,
                'run_stats__steps_skipped' => 0
            ],
            [
                [
                    'UID' => 'scenario-1',
                    'name' => "Login scenario\n# Injected heading",
                    'line' => 18,
                    'tags' => '@smoke',
                    'duration_time' => '2 seconds',
                    'run_feature__name' => 'Authentication',
                    'run_feature__UID' => '0x11f1af9c8734c9e0af9c025041000001',
                    'run_feature__filename' => 'features/login.feature',
                    'run_scenario_stats__status' => 'failed'
                ]
            ],
            [
                [
                    'UID' => 'step-1',
                    'run_scenario' => 'scenario-1',
                    'parent_step' => null,
                    'run_sequence_idx' => 1,
                    'name' => 'Given a user exists',
                    'line' => 19,
                    'status' => 100,
                    'started_on' => '2026-11-10 10:00:00',
                    'duration_ms' => 12
                ],
                [
                    'UID' => 'step-2',
                    'run_scenario' => 'scenario-1',
                    'parent_step' => 'step-1',
                    'run_sequence_idx' => 2,
                    'name' => 'Then *the login succeeds*',
                    'line' => 20,
                    'status' => 101,
                    'duration_ms' => 30,
                    'details' => "Expected | actual\nMore details",
                    'error_message' => 'Assertion failed',
                    'error_log_id' => 'A4A38062',
                    'screenshot_path' => 'data\\axenox\\BDT\\Screenshots\\run-1\\failed step.png',
                    'url' => 'https://example.test/page?value=(one)&other=two'
                ]
            ]
        );

        $this->assertStringContainsString('# Model test run', $markdown);
        $this->assertStringContainsString($headings, $markdown);
        $this->assertStringContainsString('<td>Model steps_total</td>', $markdown);
        $this->assertStringContainsString('<td>Model behat_command</td>', $markdown);
        $this->assertStringContainsString('<td>Model run_stats__status</td>', $markdown);
        $this->assertStringContainsString('| Model run\\_scenario\\_stats\\_\\_status |', $markdown);
        $this->assertStringNotContainsString('Model filename', $markdown);
        $this->assertStringNotContainsString('Open feature report', $markdown);
        $this->assertStringNotContainsString('Feature-Bericht', $markdown);
        $html = MarkdownDataType::convertMarkdownToHtml($markdown);
        $this->assertSame(3, substr_count($html, '<table>'));
        $this->assertMatchesRegularExpression('/<td>Model steps_total<\/td>\s*<td>2<\/td>/', $html);
        $this->assertStringNotContainsString('Eigenschaft', $markdown);
        $this->assertStringContainsString('## [Authentication](', $markdown);
        $this->assertStringContainsString(
            '](http://localhost/exface/exface/api/docs/exface/core/Docs/understanding_the_metamodel/Model_components.md?component=bdt_feature&selector=features%2Flogin.feature)',
            $markdown
        );
        $this->assertStringNotContainsString('/api/feature?selector=', $markdown);
        $this->assertStringContainsString('### Login scenario \\# Injected heading', $markdown);
        $this->assertStringNotContainsString("\n# Injected heading", $markdown);
        $this->assertStringContainsString($passed . '</span> Given a user exists (Model line: 19) - Model duration\\_ms: 12', $markdown);
        $this->assertStringContainsString($failed . '</span> Then \\*the login succeeds\\* (Model line: 20) - Model duration\\_ms: 30', $markdown);
        $this->assertStringContainsString('> Model details: Expected \\| actual', $markdown);
        $this->assertStringContainsString('> Model error\\_message: Assertion failed', $markdown);
        $imageUrl = 'http://localhost/exface/exface/api/files/view/axenox.BDT.run_step_screenshot/'
            . urlencode('base64URL,' . BinaryDataType::convertTextToBase64URL('data/axenox/BDT/Screenshots/run-1/failed step.png'));
        $this->assertStringContainsString(
            '[![Model screenshot\\_path: Then \\*the login succeeds\\*](' . $imageUrl . ')](' . $imageUrl . ')',
            $markdown
        );
        $this->assertStringContainsString(
            '[A4A38062](http://localhost/exface/exface/exface.core.logs.html?action=exface.Core.CallContext&context=exface.Core.DebugContext&scope=window&operation=showLogId&id=A4A38062)',
            $markdown
        );
        $this->assertStringNotContainsString('https://example.test/page', $markdown);
        $dom = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new \DOMXPath($dom);
        $grid = $xpath->query('//div[contains(@style,"display:grid")]')->item(0);
        $this->assertNotNull($grid);
        $this->assertSame(2, $xpath->query('./div/table', $grid)->length);
        $this->assertStringContainsString('repeat(auto-fit,minmax(min(100%,24rem),1fr))', $grid->getAttribute('style'));
        $this->assertSame(1, $xpath->query('//h2/a[text()="Authentication"]')->length);
        $this->assertSame(1, $xpath->query('//a[text()="A4A38062"]')->length);
        $this->assertSame(1, $xpath->query('//details[@open]')->length);
        $this->assertSame(1, $xpath->query('//details[not(@open)]')->length);
        $this->assertSame(1, $xpath->query('//li//span[contains(@style,"color:#116329")]')->length);
        $this->assertSame(1, $xpath->query('//li//span[contains(@style,"color:#a40e26")]')->length);
        $image = $dom->getElementsByTagName('img')->item(0);
        $this->assertNotNull($image);
        $this->assertSame($imageUrl, $image->getAttribute('src'));
        $this->assertSame('Model screenshot_path: Then *the login succeeds*', $image->getAttribute('alt'));
        $this->assertSame($imageUrl, $image->parentNode->getAttribute('href'));
        $this->assertSame(1, $xpath->query('ancestor::details[@open]', $image)->length);
        $this->assertSame(2, $xpath->query('ancestor::li', $image)->length);
    }

    /**
     * Rejects arbitrary files, traversal and remote screenshots before URL construction.
     */
    #[DataProvider('invalidScreenshotPaths')]
    public function testRejectsUnsafeScreenshotPaths(string $path): void
    {
        $printer = new BdtRunMarkdownPrinter($this->createMock(WorkbenchInterface::class), '0x11f1bc127645094cbc12025041000001');
        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionMethod($printer, 'buildScreenshotUrl'))->invoke($printer, $path);
    }

    /**
     * Supplies invalid artifact references without reading files or accessing the network.
     */
    public static function invalidScreenshotPaths(): array
    {
        return [
            ['https://example.test/screenshot.png'],
            ['C:\\private\\screenshot.png'],
            ['data/axenox/BDT/Screenshots/../secret.png'],
            ['data/axenox/BDT/Screenshots/run/image.svg'],
            ["data/axenox/BDT/Screenshots/run/image\0.png"]
        ];
    }

    /**
     * Returns an attachment list without loading data again when the report has no screenshots.
     */
    public function testGetFilesWithoutScreenshots(): void
    {
        $printer = new BdtRunMarkdownPrinter($this->createMock(WorkbenchInterface::class), '@smoke');
        (new \ReflectionProperty(AbstractBdtMarkdownPrinter::class, 'reportData'))->setValue($printer, [
            [], [], [['screenshot_path' => null], ['screenshot_path' => '']]
        ]);
        self::assertSame([], $printer->getFiles());
        self::assertSame([], $printer->getFiles());
    }

    /**
     * Applies the same path restrictions to AI attachments as to screenshot links.
     */
    #[DataProvider('invalidScreenshotPaths')]
    public function testGetFilesRejectsUnsafePaths(string $path): void
    {
        $printer = new BdtRunMarkdownPrinter($this->createMock(WorkbenchInterface::class), '@smoke');
        (new \ReflectionProperty(AbstractBdtMarkdownPrinter::class, 'reportData'))->setValue($printer, [
            [], [], [['screenshot_path' => $path]]
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $printer->getFiles();
    }

    /**
     * Omits recorded page URLs and prevents log IDs from injecting query parameters.
     */
    public function testDoesNotTurnUnsafeArtifactValuesIntoExecutableLinks(): void
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $workbench->method('getUrl')->willReturn('https://example.test/workbench/');
        $model = $this->createMock(Model::class);
        $workbench->method('model')->willReturn($model);
        $object = $this->createMock(MetaObjectInterface::class);
        $attribute = $this->createMock(MetaAttributeInterface::class);
        $attribute->method('getName')->willReturn('Reference');
        $object->method('getAttribute')->willReturn($attribute);
        $model->method('getObject')->willReturn($object);
        $printer = new BdtRunMarkdownPrinter($workbench, '0x11f1bc127645094cbc12025041000001');
        $markdown = (new \ReflectionMethod($printer, 'renderStepArtifacts'))->invoke($printer, [
            'url' => 'javascript:alert(1)',
            'error_log_id' => 'id&operation=other#fragment'
        ], '  ');
        $dom = new \DOMDocument();
        $dom->loadHTML(MarkdownDataType::convertMarkdownToHtml($markdown));
        $links = $dom->getElementsByTagName('a');
        self::assertSame(1, $links->length);
        $query = [];
        parse_str(parse_url($links->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
        self::assertSame('showLogId', $query['operation']);
        self::assertSame('id&operation=other#fragment', $query['id']);
        self::assertStringNotContainsString('javascript:alert(1)', $dom->textContent);
    }

    /**
     * Covers both report dictionaries while retaining model-provided field captions.
     */
    public static function reportLanguages(): array
    {
        return [
            'German' => ['de', '| Attribut | Wert |', 'Bestanden', 'Fehlgeschlagen'],
            'English' => ['en', '| Attribute | Value |', 'Passed', 'Failed']
        ];
    }
}
