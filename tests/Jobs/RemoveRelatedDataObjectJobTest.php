<?php

namespace SilverStripe\SearchService\Tests\Jobs;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\SearchService\DataObject\DataObjectDocument;
use SilverStripe\SearchService\Jobs\RemoveDataObjectJob;
use SilverStripe\SearchService\Schema\Field;
use SilverStripe\SearchService\Service\Indexer;
use SilverStripe\SearchService\Tests\Fake\DataObjectFake;
use SilverStripe\SearchService\Tests\Fake\DataObjectFakePrivate;
use SilverStripe\SearchService\Tests\Fake\DataObjectFakeVersioned;
use SilverStripe\SearchService\Tests\Fake\ImageFake;
use SilverStripe\SearchService\Tests\Fake\PageFake;
use SilverStripe\SearchService\Tests\Fake\TagFake;
use SilverStripe\SearchService\Tests\SearchServiceTest;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

class RemoveRelatedDataObjectJobTest extends SearchServiceTest
{

    protected static $fixture_file = [ // @phpcs:ignore
        '../fixtures.yml',
        '../pages.yml',
    ];

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
     * @var array
     */
    protected static $extra_dataobjects = [
        DataObjectFake::class,
        DataObjectFakePrivate::class,
        DataObjectFakeVersioned::class,
        TagFake::class,
        ImageFake::class,
        Member::class,
        PageFake::class,
    ];

    public function setUp(): void
    {
        parent::setUp();

        // Publish all pages in fixtures since the internal dependency checks looks for live version
        for ($i = 1; $i <= 8; $i++) {
            $this->objFromFixture(Page::class, 'page' . $i)->publishRecursive();
        }

        $this->objFromFixture(PageFake::class, 'page9')->publishRecursive();
        $this->objFromFixture(PageFake::class, 'page10')->publishRecursive();
        $this->objFromFixture(PageFake::class, 'page11')->publishRecursive();
        $this->objFromFixture(TagFake::class, 'four')->publishRecursive();
        $this->objFromFixture(TagFake::class, 'five')->publishRecursive();
        $this->objFromFixture(TagFake::class, 'six')->publishRecursive();

        $config = $this->mockConfig();

        $config->set(
            'getSearchableClasses',
            [
                DataObjectFake::class,
                TagFake::class,
                ImageFake::class,
                PageFake::class,
                Page::class,
            ]
        );

        $config->set(
            'getFieldsForClass',
            [
                DataObjectFake::class => [
                    new Field('title'),
                    new Field('tagtitles', 'Tags.Title'),
                ],
                PageFake::class => [
                    new Field('title'),
                    new Field('tagtitles', 'Tags.Title'),
                    new Field('images', 'Images.URL'),
                ],
                ImageFake::class => [
                    new Field('title'),
                    new Field('url'),
                    new Field('tagtitles', 'Tags.Title'),
                ],
            ]
        );

        $index = [
            'main' => [
                'includeClasses' => [
                    DataObjectFake::class => ['title' => true],
                    TagFake::class => ['title' => true],
                    ImageFake::class => ['title' => true],
                    PageFake::class => ['title' => true],
                    Page::class => ['title' => true],
                ],
            ],
        ];

        $config->set(
            'getIndexesForClassName',
            [
                DataObjectFake::class => $index,
                TagFake::class => $index,
                ImageFake::class => $index,
                PageFake::class => $index,
                Page::class => $index,
            ]
        );
    }

    public function testUnpublishParentPage(): void
    {
        $childA = $this->objFromFixture(Page::class, 'page2');
        $childB = $this->objFromFixture(Page::class, 'page3');
        $grandChildA1 = $this->objFromFixture(Page::class, 'page7');
        $grandChildA2 = $this->objFromFixture(Page::class, 'page8');

        $this->assertTrue($childA->isPublished());
        $this->assertTrue($childB->isPublished());
        $this->assertTrue($grandChildA1->isPublished());
        $this->assertTrue($grandChildA2->isPublished());

        $pageOne = $this->objFromFixture(Page::class, 'page1');
        $pageOne->doUnpublish();

        // Default behaviour when unpublishng the parent page
        $this->assertTrue(SiteTree::config()->get('enforce_strict_hierarchy'));
        $this->assertFalse($childA->isPublished());
        $this->assertFalse($childB->isPublished());
        $this->assertFalse($grandChildA1->isPublished());
        $this->assertFalse($grandChildA2->isPublished());

        // Queue up a job to remove a page with child pages are added as related documents
        $pageDoc = DataObjectDocument::create($pageOne);
        $job = RemoveDataObjectJob::create($pageDoc);
        $job->setup();

        // Creating this job does not necessarily mean to delete documents from index
        $this->assertEquals(Indexer::METHOD_ADD, $job->getMethod());

        // Grab what Documents the Job determined it needed to action
        /** @var DataObjectDocument[] $documents */
        $documents = $job->getDocuments();

        // There should be two Pages with this Tag assigned
        $this->assertCount(4, $documents);

        $expectedTitles = [
            'Child of Parent Page 1 - A',
            'Child of Parent Page 1 - B',
            'Grandchild of Parent Page 1 - A1',
            'Grandchild of Parent Page 1 - A2',
        ];

        $resultTitles = [];

        // This determines whether the document should be added or removed from from the index
        foreach ($documents as $document) {
            $resultTitles[] = $document->getDataObject()?->Title;

            // The document should be removed from index
            $this->assertFalse($document->shouldIndex());
        }

        $this->assertEqualsCanonicalizing($expectedTitles, $resultTitles);
    }

    public function testUnpublishRelatedTagsObject(): void
    {
        $tagFour = $this->objFromFixture(TagFake::class, 'four');
        $tagFour->doUnpublish();

        // Queue up a job to remove our Tag, the result should be that any related DataObject (DOs that have this Tag
        // assigned to them) are added as related Documents
        $job = RemoveDataObjectJob::create(
            DataObjectDocument::create($tagFour)
        );
        $job->setup();

        // Creating this job does not necessarily mean to delete documents from index
        $this->assertEquals(Indexer::METHOD_ADD, $job->getMethod());

        // Grab what Documents the Job determined it needed to action
        /** @var DataObjectDocument[] $documents */
        $documents = $job->getDocuments();

        // There should be two Pages with this Tag assigned
        $this->assertCount(3, $documents);

        $expectedTitles = [
            'Child of Parent Page 2 - B',
            'Great Grandchild of Parent Page 2 - B1 - One',
            'Image Fake Three',
        ];

        $pageChild = $this->objFromFixture(PageFake::class, 'page9');
        $grandChild = $this->objFromFixture(PageFake::class, 'page10');
        $greatGrandChild = $this->objFromFixture(PageFake::class, 'page11');
        $imageThree = $this->objFromFixture(ImageFake::class, 'three');
        $imageFour = $this->objFromFixture(ImageFake::class, 'four');

        $resultTitles = [];

        // This determines whether the document should be added or removed from from the index
        foreach ($documents as $document) {
            $resultTitles[] = $document->getDataObject()?->Title;

            // The document should be added to index
            $this->assertTrue($document->shouldIndex());
        }

        $this->assertEqualsCanonicalizing(
            $expectedTitles,
            $resultTitles
        );

        $this->assertEqualsCanonicalizing(
            $expectedTitles,
            [
                $pageChild->Title,
                $greatGrandChild->Title,
                $imageThree->Title,
            ],
        );

        Versioned::withVersionedMode(function () use (
            $tagFour,
            $pageChild,
            $greatGrandChild
        ): void {
            Versioned::set_stage(Versioned::LIVE);

            $this->assertNotContains($tagFour->ID, $pageChild->Tags()->column('ID'));
            $this->assertNotContains($tagFour->ID, $greatGrandChild->Tags()->column('ID'));
        });


        $tagFive = $this->objFromFixture(TagFake::class, 'five');
        $tagFive->doUnpublish();
        // Queue up a job to remove our Tag, the result should be that any related DataObject (DOs that have this Tag
        // assigned to them) are added as related Documents
        $job2 = RemoveDataObjectJob::create(
            DataObjectDocument::create($tagFive)
        );
        $job2->setup();

        // Creating this job does not necessarily mean to delete documents from index
        $this->assertEquals(Indexer::METHOD_ADD, $job->getMethod());

        // Grab what Documents the Job determined it needed to action
        /** @var DataObjectDocument[] $documents */
        $documents = $job2->getDocuments();

        $resultTitles = [];

        foreach ($documents as $document) {
            $resultTitles[] = $document->getDataObject()?->Title;

            // The document should be added to index
            $this->assertTrue($document->shouldIndex());
        }

        // There should be two Pages with this Tag assigned
        $this->assertCount(4, $documents);

        $expectedTitles = [
            'Child of Parent Page 2 - B',
            'Grandchild of Parent Page 2 - B1',
            'Image Fake Three',
            'Image Fake Four',
        ];

        $this->assertEqualsCanonicalizing($expectedTitles, $resultTitles);
        $this->assertEqualsCanonicalizing(
            $expectedTitles,
            [
                $pageChild->Title,
                $grandChild->Title,
                $imageThree->Title,
                $imageFour->Title,
            ],
        );

        Versioned::withVersionedMode(function () use (
            $tagFive,
            $pageChild,
            $grandChild,
            $imageThree,
            $imageFour,
        ): void {
            Versioned::set_stage(Versioned::LIVE);

            $this->assertNotContains($tagFive->ID, $pageChild->Tags()->column('ID'));
            $this->assertNotContains($tagFive->ID, $grandChild->Tags()->column('ID'));
            $this->assertNotContains($tagFive->ID, $imageThree->Tags()->column('ID'));
            $this->assertNotContains($tagFive->ID, $imageFour->Tags()->column('ID'));
        });

        // Then unpublish these related pages to include them to the documents to be removed
        $pageChild->doUnpublish();
        $grandChild->doUnpublish();
        $imageThree->delete();
        $imageFour->delete();

        /** @var DataObjectDocument[] $documents */
        $documents = $job2->getDocuments();
        $resultTitles = [];

        foreach ($documents as $document) {
            $resultTitles[] = $document->getDataObject()?->Title;

            // The document should be removed from index
            $this->assertFalse($document->shouldIndex());
        }

        // There should be two Pages with this Tag assigned
        $this->assertCount(4, $documents);

        $expectedTitles = [
            'Child of Parent Page 2 - B',
            'Grandchild of Parent Page 2 - B1',
            'Image Fake Three',
            'Image Fake Four',
        ];

        $this->assertEqualsCanonicalizing($expectedTitles, $resultTitles);
        $this->assertEqualsCanonicalizing(
            $expectedTitles,
            [
                $pageChild->Title,
                $grandChild->Title,
                $imageThree->Title,
                $imageFour->Title,
            ],
        );
    }

    public function testUnpublishImageRelatedtoPages(): void
    {
        $greatGrandChild = $this->objFromFixture(PageFake::class, 'page11');

        $this->assertTrue($greatGrandChild->isPublished());

        $imageFive = $this->objFromFixture(ImageFake::class, 'five');
        // Queue up a job to remove our Image, the result should be that any related DataObject
        $job = RemoveDataObjectJob::create(
            DataObjectDocument::create($imageFive)
        );
        $job->setup();

        // Creating this job does not necessarily mean to delete documents from index
        $this->assertEquals(Indexer::METHOD_ADD, $job->getMethod());

        // Grab what Documents the Job determined it needed to action
        /** @var DataObjectDocument[] $documents */
        $documents = $job->getDocuments();

        // There should be two Pages with this Tag assigned
        $this->assertCount(1, $documents);

        $expectedTitles = [
            'Great Grandchild of Parent Page 2 - B1 - One',
        ];

        $resultTitles = [];

        // This determines whether the document should be added or removed from from the index
        foreach ($documents as $document) {
            $resultTitles[] = $document->getDataObject()?->Title;

            // The document should be added to index
            $this->assertTrue($document->shouldIndex());
        }

        $this->assertEqualsCanonicalizing(
            $expectedTitles,
            $resultTitles
        );

        $this->assertEqualsCanonicalizing(
            $expectedTitles,
            [
                $greatGrandChild->Title,
            ],
        );
    }

}
