<?php
namespace Psalm\Tests;

use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psalm\ErrorBaseline;
use Psalm\Exception\ConfigException;
use Psalm\Provider\FileProvider;

class ErrorBaselineTest extends TestCase
{
    /** @var ObjectProphecy */
    private $fileProvider;

    /**
     * @return void
     */
    public function setUp()
    {
        $this->fileProvider = $this->prophesize(FileProvider::class);
    }

    /**
     * @return void
     */
    public function testLoadShouldParseXmlBaselineToPhpArray()
    {
        $baselineFilePath = 'baseline.xml';

        $this->fileProvider->fileExists($baselineFilePath)->willReturn(true);
        $this->fileProvider->getContents($baselineFilePath)->willReturn(
            '<?xml version="1.0" encoding="UTF-8"?>
            <files>
              <file src="sample/sample-file.php">
                <MixedAssignment occurrences="2"/>
                <InvalidReturnStatement occurrences="1"/>
              </file>
              <file src="sample/sample-file2.php">
                <PossiblyUnusedMethod occurrences="2"/>
              </file>
            </files>'
        );

        $expectedParsedBaseline = [
            'sample/sample-file.php' => [
                'MixedAssignment' => 2,
                'InvalidReturnStatement' => 1,
            ],
            'sample/sample-file2.php' => [
                'PossiblyUnusedMethod' => 2,
            ],
        ];

        $this->assertEquals(
            $expectedParsedBaseline,
            ErrorBaseline::read($this->fileProvider->reveal(), $baselineFilePath)
        );
    }

    /**
     * @return void
     */
    public function testLoadShouldThrowExceptionWhenFilesAreNotDefinedInBaselineFile()
    {
        $this->expectException(ConfigException::class);

        $baselineFile = 'baseline.xml';

        $this->fileProvider->fileExists($baselineFile)->willReturn(true);
        $this->fileProvider->getContents($baselineFile)->willReturn(
            '<?xml version="1.0" encoding="UTF-8"?>
             <other>
             </other>
            '
        );

        ErrorBaseline::read($this->fileProvider->reveal(), $baselineFile);
    }

    /**
     * @return void
     */
    public function testLoadShouldThrowExceptionWhenBaselineFileDoesNotExist()
    {
        $this->expectException(ConfigException::class);

        $baselineFile = 'baseline.xml';

        $this->fileProvider->fileExists($baselineFile)->willReturn(false);

        ErrorBaseline::read($this->fileProvider->reveal(), $baselineFile);
    }

    /**
     * @return void
     */
    public function testCreateShouldAggregateIssuesPerFile()
    {
        $baselineFile = 'baseline.xml';

        $documentContent = null;

        $this->fileProvider->setContents(
            $baselineFile,
            Argument::that(function (string $document) use (&$documentContent): bool {
                $documentContent = $document;

                return true;
            })
        )->willReturn(null);

        ErrorBaseline::create($this->fileProvider->reveal(), $baselineFile, [
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'MixedAssignment',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'MixedAssignment',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'MixedAssignment',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'MixedOperand',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'AssignmentToVoid',
                'severity' => 'info',
            ],
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'CircularReference',
                'severity' => 'suppress',
            ],
            [
                'file_name' => 'sample/sample-file2.php',
                'type' => 'MixedAssignment',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file2.php',
                'type' => 'MixedAssignment',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file2.php',
                'type' => 'TypeCoercion',
                'severity' => 'error',
            ],
        ]);

        $baselineDocument = new \DOMDocument();
        $baselineDocument->loadXML($documentContent, LIBXML_NOBLANKS);

        /** @var \DOMElement[] $files */
        $files = $baselineDocument->getElementsByTagName('files')[0]->childNodes;

        $file1 = $files[0];
        $file2 = $files[1];
        $this->assertEquals('sample/sample-file.php', $file1->getAttribute('src'));
        $this->assertEquals('sample/sample-file2.php', $file2->getAttribute('src'));

        /** @var \DOMElement[] $file1Issues */
        $file1Issues = $file1->childNodes;
        /** @var \DOMElement[] $file2Issues */
        $file2Issues = $file2->childNodes;

        $this->assertEquals('MixedAssignment', $file1Issues[0]->tagName);
        $this->assertEquals(3, $file1Issues[0]->getAttribute('occurrences'));
        $this->assertEquals('MixedOperand', $file1Issues[1]->tagName);
        $this->assertEquals(1, $file1Issues[1]->getAttribute('occurrences'));

        $this->assertEquals('MixedAssignment', $file2Issues[0]->tagName);
        $this->assertEquals(2, $file2Issues[0]->getAttribute('occurrences'));
        $this->assertEquals('TypeCoercion', $file2Issues[1]->tagName);
        $this->assertEquals(1, $file2Issues[1]->getAttribute('occurrences'));
    }

    /**
     * @return void
     */
    public function testUpdateShouldRemoveExistingIssuesWithoutAddingNewOnes()
    {
        $baselineFile = 'baseline.xml';

        $this->fileProvider->fileExists($baselineFile)->willReturn(true);
        $this->fileProvider->getContents($baselineFile)->willReturn(
            '<?xml version="1.0" encoding="UTF-8"?>
            <files>
              <file src="sample/sample-file.php">
                <MixedAssignment occurrences="3"/>
                <MixedOperand occurrences="1"/>
              </file>
              <file src="sample/sample-file2.php">
                <MixedAssignment occurrences="2"/>
                <TypeCoercion occurrences="1"/>
              </file>
              <file src="sample/sample-file3.php">
                <MixedAssignment occurrences="1"/>
              </file>
            </files>'
        );
        $this->fileProvider->setContents(Argument::cetera())->willReturn(null);

        $newIssues = [
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'MixedAssignment',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'MixedAssignment',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'MixedOperand',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file.php',
                'type' => 'MixedOperand',
                'severity' => 'error',
            ],
            [
                'file_name' => 'sample/sample-file2.php',
                'type' => 'TypeCoercion',
                'severity' => 'error',
            ],
        ];

        $remainingBaseline = ErrorBaseline::update(
            $this->fileProvider->reveal(),
            $baselineFile,
            $newIssues
        );

        $this->assertEquals([
            'sample/sample-file.php' => [
                'MixedAssignment' => 2,
                'MixedOperand' => 1,
            ],
            'sample/sample-file2.php' => [
                'TypeCoercion' => 1,
            ],
        ], $remainingBaseline);
    }
}