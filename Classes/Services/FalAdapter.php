<?php

namespace Mfd\Ai\FileMetadata\Services;

use Mfd\Ai\FileMetadata\Api\OpenAiClient;
use Mfd\Ai\FileMetadata\Sites\SiteLanguageProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Resource\Search\FileSearchDemand;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

class FalAdapter
{
    private array $siteLanguageMapping = [];

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly ConfigurationService $configurationService,
        private readonly SiteLanguageProvider $languageProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function iterate(
        Folder $folder,
        bool $overwriteMetadata,
        ?int $limit = null,
        ?OutputInterface $output = null
    ) {
        if (!$output) {
            $output = new NullOutput();
        }

        $this->siteLanguageMapping = $this->languageProvider->getFalLanguages();

        $fileSearch = FileSearchDemand::create()
            // Sure, native support for empty searches or at least recursive iterators would be better than this
            ->withSearchTerm(' ')
            ->withRecursive();

        // if ($limit !== null) {
        //     $fileSearch = $fileSearch->withMaxResults($limit);
        // }

        $files = $folder->searchFiles($fileSearch);

        $processedCount = 0;

        $progress = new ProgressBar($output);
        $progress->setFormat('with_message');
        $progress->setMessage('');
        $progress->setRedrawFrequency(25);
        foreach ($progress->iterate($files) as $file) {
            if ($limit !== null && $processedCount >= $limit) {
                break;
            }

            if (!in_array($file->getExtension(), ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                continue;
            }

            if ($this->configurationService->shouldBeExcluded($file)) {
                continue;
            }

            // Check if file actually needs localization (no existing alt-text in default language, or translations missing, or overwrite enabled)
            $needsLocalization = false;
            $originalMetadata = $file->getMetaData()->get();
            $existingAlternative = trim($originalMetadata['alternative'] ?? '');
            if ($overwriteMetadata || $existingAlternative === '') {
                $needsLocalization = true;
            } else {
                // Check for missing translations in other languages
                $falLanguages = $this->getLanguageMappingForFile($file);
                foreach ($falLanguages as $sysLanguageUid => $locale) {
                    if ($sysLanguageUid === 0) {
                        continue;
                    }
                    $translatedRecords = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecordLocalization(
                        'sys_file_metadata',
                        $originalMetadata['uid'],
                        $sysLanguageUid
                    );
                    $translatedAlternative = trim($translatedRecords[0]['alternative'] ?? '');
                    if ($overwriteMetadata || $translatedAlternative === '') {
                        $needsLocalization = true;
                        break;
                    }
                }
            }
            if (!$needsLocalization) {
                // No changes needed, skip counting and processing
                continue;
            }

            $progress->setMessage($file->getIdentifier());
            $this->localizeFile($file, $overwriteMetadata);
            $processedCount++;
        }
    }

    private function getLanguageMappingForFile(File $file): array
    {
        return $this->configurationService->getLanguageMappingForFile($file) ?? $this->siteLanguageMapping;
    }

    public function localizeFile(File $file, bool $overwriteMetadata)
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $originalMetadata = $file->getMetaData()->get();
        if (isset($originalMetadata['uid'])) {
            $metadataUid = [
                0 => $originalMetadata['uid'],
            ];
        } else {
            $metadataAspect = GeneralUtility::makeInstance(MetadataAspect::class, $file);
            $originalMetadata = $metadataAspect->get();
            $metadataUid = [
                0 => $originalMetadata['uid'],
            ];
        }

        $falLanguages = $this->getLanguageMappingForFile($file);
        $this->logger->debug(
            'Localize {file} with language mapping {languages}',
            [
                'file' => $file->getIdentifier(),
                'languages' => $falLanguages,
            ]
        );

        foreach ($falLanguages as $sysLanguageUid => $locale) {
            if ($sysLanguageUid === 0) {
                continue;
            }

            $translatedRecords = BackendUtility::getRecordLocalization(
                'sys_file_metadata',
                $metadataUid[0],
                $sysLanguageUid,
            );

            if (!isset($translatedRecords[0])) {
                $metadataUid[$sysLanguageUid] = StringUtility::getUniqueId('NEW');
                continue;
            }

            if (!$overwriteMetadata && !empty(trim($translatedRecords[0]['alternative'] ?? ''))) {
                continue;
            }

            $metadataUid[$sysLanguageUid] = $translatedRecords[0]['uid'];
        }

        $metadata = [];
        foreach (array_keys($metadataUid) as $sysLanguageUid) {
            if ($sysLanguageUid === 0) {
                if (!$overwriteMetadata && !empty(trim($originalMetadata['alternative'] ?? ''))) {
                    continue;
                }
            }

            $altText = $this->openAiClient->buildAltText(
                $file->getContents(),
                $falLanguages[$sysLanguageUid]
            );
            $metadata[$metadataUid[$sysLanguageUid]] = [
                'pid' => $originalMetadata['pid'],
                'sys_language_uid' => $sysLanguageUid,
                'l10n_parent' => $sysLanguageUid === 0 ? 0 : $metadataUid[0],
                'file' => $originalMetadata['file'],
                'alternative' => $altText,
            ];
        }

        $cmd = [];
        $data = [
            'sys_file_metadata' => $metadata,
        ];

        $dataHandler->admin = true;
        $dataHandler->bypassAccessCheckForRecords = true;
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->BE_USER->user['admin'] = 1;
        $dataHandler->userid = $GLOBALS['BE_USER']->user['uid'];
        $dataHandler->start($data, $cmd);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            DebuggerUtility::var_dump($dataHandler->errorLog);
            throw new \RuntimeException('Error while mass updating file metadata');
        }
    }
}
