<?php

namespace Mfd\Ai\FileMetadata\Command;

use Mfd\Ai\FileMetadata\Api\OpenAiClient;
use Mfd\Ai\FileMetadata\Sites\SiteLanguageProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Search\FileSearchDemand;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

class GenerateAltTextsCommand extends Command
{
    private array $falLanguages = [];
    private bool $doOverwriteMetadata;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly SiteLanguageProvider $languageProvider,
        private readonly OpenAiClient $openAiClient,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->addOption(
                'path',
                mode: InputOption::VALUE_REQUIRED,
                description: 'FAL path to start alt text generation from',
            )
            ->addOption(
                'limit',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Limit operation to a maximum number of files',
            )
            ->addOption(
                'overwrite',
                mode: InputOption::VALUE_NONE,
                description: 'Overwrite existing metadata?',
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        ProgressBar::setFormatDefinition('with_message', ' %current%/%max% [%bar%] %message%');
        Bootstrap::initializeBackendAuthentication();

        $this->doOverwriteMetadata = $input->getOption('overwrite');
        $limit = $input->getOption('limit');

        if (($path = $input->getOption('path')) !== null) {
            $storage = $this->storageRepository->findByCombinedIdentifier($path);
            $folder = $storage->getFolder(substr($path, strpos($path, ':') + 1));
        } else {
            $storage = $this->storageRepository->getDefaultStorage();
            $folder = $storage->getRootLevelFolder();
        }

        $fileSearch = FileSearchDemand::create()
            // Sure, native support for empty searches or at least recursive iterators would be better than this
            ->withSearchTerm(' ')
            ->withRecursive();

        if ($limit) {
            $fileSearch->withMaxResults($limit);
        }

        $files = $folder->searchFiles($fileSearch);

        $io = new SymfonyStyle($input, $output);
        $io->section('Generating new alternative texts');

        $this->falLanguages = $this->languageProvider->getFalLanguages();

        $progress = new ProgressBar($output);
        $progress->setFormat('with_message');
        $progress->setMessage('');
        foreach ($progress->iterate($files) as $file) {
            $progress->setMessage($file->getIdentifier());
            $this->localizeFile($file);
        }

        return 0;
    }

    private function localizeFile(File $file)
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $originalMetadata = $file->getMetaData()->get();
        $metadataUid = [
            0 => $originalMetadata['uid'],
        ];

        foreach ($this->falLanguages as $sysLanguageUid => $locale) {
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

            if (!$this->doOverwriteMetadata && !empty(trim($translatedRecords[0]['alternative']))) {
                continue;
            }

            $metadataUid[$sysLanguageUid] = $translatedRecords[0]['uid'];
        }

        $metadata = [];
        foreach (array_keys($metadataUid) as $sysLanguageUid) {
            if ($sysLanguageUid === 0) {
                if (!$this->doOverwriteMetadata && !empty(trim($originalMetadata['alternative']))) {
                    continue;
                }
            }

            $altText = $this->openAiClient->buildAltText(
                $file->getContents(),
                $this->falLanguages[$sysLanguageUid]
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

        $dataHandler->start($data, $cmd);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            dump($dataHandler->errorLog);
            throw new \RuntimeException('Error while mass updating file metadata');
        }
    }
}
