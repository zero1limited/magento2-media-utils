<?php
namespace Zero1\MediaUtils\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Zero1\MediaUtils\Service\ImageResize;
use Magento\Framework\Filesystem;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Product\Image as ProductImage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Model\View\Asset\ImageFactory as AssetImageFactory;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressBarFactory;
use Symfony\Component\Console\Input\InputOption;

/**
 * This logic is pulled from \Magento\MediaStorage\Service\ImageResize
 */
class ImageClean extends Command
{
    /** @var State */
    protected $appState;

    /** @var ImageResize */
    protected $imageResize;

    /** @var FileSystem */
    protected $filesystem;

    /** @var MediaConfig */
    /** @var \Magento\Catalog\Model\Product\Media\Config */
    protected $imageConfig;

    /** @var AssetImageFactory */
    protected $imageFactory;

    /** @var ProductImage */
    protected $productImage;

    /** @var ProgressBarFactory */
    protected $progressBarFactory;

    public function __construct(
        State $appState,
        ImageResize $imageResize,
        Filesystem $filesystem,
        MediaConfig $imageConfig,
        AssetImageFactory $assetImageFactory,
        ProductImage $productImage,
        ProgressBarFactory $progressBarFactory
    ){
        $this->appState = $appState;
        $this->imageResize = $imageResize;
        $this->filesystem = $filesystem;
        $this->imageConfig = $imageConfig;
        $this->imageFactory = $assetImageFactory;
        $this->productImage = $productImage;
        $this->progressBarFactory = $progressBarFactory;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('zero1:media-utils:product-clean');
        $this->setDescription('remove unused product images');
        $this->setDefinition([
            new InputOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Run without taking any action',
            )
        ]);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {        
        $isDryrun = $input->getOption('dry-run');
        $this->appState->setAreaCode(Area::AREA_GLOBAL);

        /** @var \Magento\Framework\Filesystem\Directory\Write $mediaDirectory */
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        /** @var \Magento\Catalog\Model\View\Asset\Image $imageAsset  */
        $imageAsset = $this->imageFactory->create(['filePath' => '', 'miscParams' => []]);
        $cacheDirectory = $imageAsset->getModule();
        $productImageDirectory = $this->imageConfig->getBaseMediaPathAddition();

        
        $output->writeln('scanning product image directory...');
        $allEntries = $mediaDirectory->readRecursively($productImageDirectory);
        usort($allEntries, function($a, $b){
            $aLength = strlen($a);
            $bLength = strlen($b);

            if($aLength > $bLength){
                return -1;
            }else if($aLength < $bLength){
                return 1;
            }
            return 0;
        });

        $fullPathedEntries = [];
        $totalFileSize = 0;
        $totalFiles = 0;
        foreach($allEntries as $path){
            // exclude the cache directory
            if(strpos($path, DIRECTORY_SEPARATOR.$cacheDirectory.DIRECTORY_SEPARATOR) !== false){
                continue;
            }
            if($mediaDirectory->isFile($path)){
                $fileStats = $mediaDirectory->stat($path);
                $totalFileSize += $fileStats['size'];
                $totalFiles++;
                $fullPathedEntries[$mediaDirectory->getAbsolutePath($path)] = $fileStats['size'];
            }else{
                $fullPathedEntries[$mediaDirectory->getAbsolutePath($path)] = 0;
            }            
        }
        $output->writeln(sprintf('<info>%d</info> files found in image directory, excluding cache (<info>%s</info>)', $totalFiles, $this->toHumanReadableSize($totalFileSize)));

        $validProductImages = $this->productImage->getAllProductImages();
        $validProductImagesCount = $this->productImage->getCountAllProductImages();

        $output->writeln('Excluding valid images (<info>'.$validProductImagesCount
            .' product images to process</info>)');

        /** @var ProgressBar $progress */
        $progress = $this->progressBarFactory->create(
            [
                'output' => $output,
                'max' => $validProductImagesCount
            ]
        );
        $progress->setFormat('debug');
        $progress->start();

        foreach($validProductImages as $image){
            $absolutePathToImage = $mediaDirectory->getAbsolutePath($productImageDirectory.$image['filepath']);
            
            if(isset($fullPathedEntries[$absolutePathToImage])){
                $totalFileSize -= $fullPathedEntries[$absolutePathToImage];
                unset($fullPathedEntries[$absolutePathToImage]);
                $totalFiles--;
            }
            $progress->advance();
        }
        $progress->finish();
        $output->writeln('');

        $output->writeln(sprintf('<info>%d</info> files to delete (<info>%s</info>)', $totalFiles, $this->toHumanReadableSize($totalFileSize)));

        if($isDryrun){
            return Cli::RETURN_SUCCESS;
        }

        if($totalFiles == 0){
            $output->writeln('<info>Complete, no files to remove</info>');
            return Cli::RETURN_SUCCESS;
        }

        /** @var ProgressBar $progress */
        $progress = $this->progressBarFactory->create(
            [
                'output' => $output,
                'max' => count($fullPathedEntries)
            ]
        );
        $progress->setFormat('debug');
        $progress->start();

        foreach($fullPathedEntries as $filepath => $size){
            if($mediaDirectory->isFile($filepath)){
                $mediaDirectory->delete($filepath);
            }else{
                // remove empty directories
                $files = $mediaDirectory->read($filepath);
                if(count($files) == 0){
                    $mediaDirectory->delete($filepath);
                }
            }
            $progress->advance();
        }
        $progress->finish();
        $output->writeln('');


        $output->writeln('<info>Complete</info>');
        return Cli::RETURN_SUCCESS;
    }

    protected function toHumanReadableSize($size)
    {
        if ($size >= 1073741824) {
          $fileSize = round($size / 1024 / 1024 / 1024,1) . 'GB';
        } elseif ($size >= 1048576) {
            $fileSize = round($size / 1024 / 1024,1) . 'MB';
        } elseif($size >= 1024) {
            $fileSize = round($size / 1024,1) . 'KB';
        } else {
            $fileSize = $size . ' bytes';
        }
        return $fileSize;
    }
}