<?php
namespace Zero1\MediaUtils\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Helper\Table;
use Zero1\MediaUtils\Service\ImageResize;

/**
 * This logic is pulled from \Magento\MediaStorage\Service\ImageResize
 */
class ImageVariationReport extends Command
{
    /** @var State */
    protected $appState;

    /** @var ImageResize */
    protected $imageResize;

    public function __construct(
        State $appState,
        ImageResize $imageResize
    ){
        $this->appState = $appState;
        $this->imageResize = $imageResize;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('zero1:media-utils:product-image-variation-report');
        $this->setDescription('Display all the image variations across ');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {        
        $this->appState->setAreaCode(Area::AREA_GLOBAL);
        $images = $this->imageResize->getImageVariants();
        $tableData = [];
        foreach($images as $image){
            $tableData[] = [
                $image['theme'],
                implode(',', $image['stores']),
                $image['id'],
                $image['image_type'],
                $image['image_height'],
                $image['image_width'],
                implode(',', $image['background']),
                $image['angle'],
                $image['quality'],
                $image['keep_aspect_ratio'],
                $image['keep_frame'],
                $image['keep_transparency'],
                $image['constrain_only'],
            ];
        }

        usort($tableData, function($a, $b){
            $idComp = strcmp($a[3], $b[3]);
            if($idComp === 0){
                $aSize = (int)$a[5] * (int)$a[4];
                $bSize = (int)$b[5] * (int)$b[4];
                if($aSize < $bSize){
                    return -1;
                }elseif($aSize > $bSize){
                    return 1;
                }
                return 0;
            }
            return $idComp;
        });

        $output->writeln('Image Configurations');
        $table = new Table($output);
        $table
            ->setHeaders([
                'Theme', 
                'Store IDs', 
                'Image ID',
                'Image Type', 
                'Image Height',
                'Image Width',
                'Background',
                'Angle',
                'Quality',
                'Keep Aspect Ratio',
                'Keep Frame',
                'Keep Transparency',
                'Constrain Only',
            ])
            ->setRows($tableData);
        $table->render();
        
        $output->writeln('Total Variants: '.count($images));
        $output->writeln('');

        $cacheToVariantsMap = $this->imageResize->getCacheDirectoryToVariantsMap();

        $tableData = [];
        foreach($cacheToVariantsMap as $cacheDirectory => $variants){
            $isFirst = true;
            foreach($variants as $variant){
                if($isFirst){
                    $tableData[] = [
                        $cacheDirectory,
                        $variant['theme'],
                        implode(',', $variant['stores']),
                        $variant['id'],
                    ];
                    $isFirst = false;
                }else{
                    $tableData[] = [
                        '',
                        $variant['theme'],
                        implode(',', $variant['stores']),
                        $variant['id'],
                    ];
                }
            }
        }
        
        $output->writeln('Cache Directory To Image Variant');
        $table = new Table($output);
        $table
            ->setHeaders([
                'Cache Directory',
                'Theme', 
                'Store IDs', 
                'Image ID',
            ])
            ->setRows($tableData);
        $table->render();
        $output->writeln('Total Cache Directories: '.count($cacheToVariantsMap));

        return 0;
    }
}