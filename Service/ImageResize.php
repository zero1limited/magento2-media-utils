<?php
namespace Zero1\MediaUtils\Service;

use Generator;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product\Image\ParamsBuilder;
use Magento\Catalog\Model\View\Asset\ImageFactory as AssertImageFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Filesystem;
use Magento\Framework\Image\Factory as ImageFactory;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Framework\App\State;
use Magento\Framework\View\ConfigInterface as ViewConfig;
use Magento\Catalog\Model\ResourceModel\Product\Image as ProductImage;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Model\Config\Customization as ThemeCustomizationConfig;
use Magento\Theme\Model\ResourceModel\Theme\Collection as ThemeCollection;
use Magento\MediaStorage\Helper\File\Storage\Database as FileStorageDatabase;
use Magento\Theme\Model\Theme;
use Magento\MediaStorage\Service\ImageResize as CoreImageResize;
use Zero1\MediaUtils\Model\View\Asset\ImageFactory as AssetImageFactory;

class ImageResize extends CoreImageResize
{
    /** @var State */
    protected $appState;

    /** @var ThemeCustomizationConfig */
    protected $themeCustomizationConfig;

    /** @var ThemeCollection */
    protected $themeCollection;

    /** @var ParamsBuilder */
    protected $paramsBuilder;

    /** @var ViewConfig */
    protected $viewConfig;

    /** @var StoreManagerInterface */
    protected $storeManager;
    
    /** @var AssetImageFactory */
    protected $assetImageFactory;

    /** @var array */
    protected $cachedImageVariants;

    /**
     * @param State $appState
     * @param MediaConfig $imageConfig
     * @param ProductImage $productImage
     * @param ImageFactory $imageFactory
     * @param ParamsBuilder $paramsBuilder
     * @param ViewConfig $viewConfig
     * @param AssertImageFactory $assertImageFactory
     * @param ThemeCustomizationConfig $themeCustomizationConfig
     * @param ThemeCollection $themeCollection
     * @param Filesystem $filesystem
     * @param FileStorageDatabase $fileStorageDatabase
     * @param StoreManagerInterface $storeManager
     * @throws \Magento\Framework\Exception\FileSystemException
     * @internal param ProductImage $gallery
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        State $appState,
        MediaConfig $imageConfig,
        ProductImage $productImage,
        ImageFactory $imageFactory,
        ParamsBuilder $paramsBuilder,
        ViewConfig $viewConfig,
        AssertImageFactory $assertImageFactory,
        ThemeCustomizationConfig $themeCustomizationConfig,
        ThemeCollection $themeCollection,
        Filesystem $filesystem,
        FileStorageDatabase $fileStorageDatabase = null,
        StoreManagerInterface $storeManager = null
    ) {
        
        $this->appState = $appState;
        $this->themeCollection = $themeCollection;
        $this->themeCustomizationConfig = $themeCustomizationConfig;
        $this->paramsBuilder = $paramsBuilder;
        $this->viewConfig = $viewConfig;
        $this->assetImageFactory = $assertImageFactory;
        $this->storeManager = $storeManager ?? ObjectManager::getInstance()->get(StoreManagerInterface::class);

        parent::__construct(
            $appState,
            $imageConfig,
            $productImage,
            $imageFactory,
            $paramsBuilder,
            $viewConfig,
            $assertImageFactory,
            $themeCustomizationConfig,
            $themeCollection,
            $filesystem,
            $fileStorageDatabase,
            $this->storeManager
        );
    }

    public function getImageVariants($cached = false)
    {
        if($cached){
            if(!$this->cachedImageVariants){
                $this->cachedImageVariants = $this->getViewImages($this->getThemesInUse());    
            }
            return $this->cachedImageVariants;
        }else{
            $this->cachedImageVariants = $this->getViewImages($this->getThemesInUse());
            return $this->cachedImageVariants;
        }
    }

    public function getCacheDirectoryToVariantsMap()
    {
        $imageVariants = $this->getImageVariants();
        $map = [];

        foreach($imageVariants as $imageVariant){
            $imageVariantCopy = $imageVariant;
            unset($imageVariantCopy['theme']);
            unset($imageVariantCopy['stores']);
            unset($imageVariantCopy['id']);

            /** @var \Magento\Catalog\Model\View\Asset\Image $imageAsset  */
            $imageAsset = $this->assetImageFactory->create(
                [
                    'miscParams' => $imageVariantCopy,
                    'filePath' => 'test.jpg',
                ]
            );
            $path = explode('/', $imageAsset->getPath());
            $cacheHash = $path[(array_search('cache', $path) + 1)];
            if(!isset($map[$cacheHash])){
                $map[$cacheHash] = [];
            }
            $map[$cacheHash][] = $imageVariant;
        }
        ksort($map);
        return $map;
    }

    public function getAllVariationsOfImage($path)
    {
        $imageVariants = $this->getImageVariants(true);
        $variations = [];
        foreach($imageVariants as $imageVariant){
            $imageVariantCopy = $imageVariant;
            unset($imageVariantCopy['theme']);
            unset($imageVariantCopy['stores']);
            unset($imageVariantCopy['id']);

            /** @var \Magento\Catalog\Model\View\Asset\Image $imageAsset  */
            $imageAsset = $this->assetImageFactory->create(
                [
                    'miscParams' => $imageVariantCopy,
                    'filePath' => $path,
                ]
            );
            $variations[] = $imageAsset->getPath();
        }
        return $variations;
    }

    

     /**
     * return array of all in use themese
     *
     * @return array
     */
    protected function getThemesInUse(): array
    {
        $themesInUse = [];
        $registeredThemes = $this->themeCollection->loadRegisteredThemes();
        $storesByThemes = $this->themeCustomizationConfig->getStoresByThemes();
        $keyType = is_integer(key($storesByThemes)) ? 'getId' : 'getCode';
        foreach ($registeredThemes as $registeredTheme) {
            if (array_key_exists($registeredTheme->$keyType(), $storesByThemes)) {
                $themesInUse[] = $registeredTheme;
            }
        }
        return $themesInUse;
    }

    /**
     * Get view images data from themes.
     *
     * @param array $themes
     * @return array
     */
    protected function getViewImages(array $themes): array
    {
        $viewImages = [];
        $stores = $this->storeManager->getStores(true);
        /** @var Theme $theme */
        foreach ($themes as $theme) {
            $config = $this->viewConfig->getViewConfig(
                [
                    'area' => Area::AREA_FRONTEND,
                    'themeModel' => $theme,
                ]
            );
            $images = $config->getMediaEntities('Magento_Catalog', ImageHelper::MEDIA_TYPE_CONFIG_NODE);
            foreach ($images as $imageId => $imageData) {
                foreach ($stores as $store) {
                    $data = $this->paramsBuilder->build($imageData, (int) $store->getId());
                    $data['theme'] = $theme->getThemeId();
                    $uniqIndex = $this->getUniqueImageIndex($data);
                    $data['id'] = $imageId;
                    if(!isset($viewImages[$uniqIndex])){
                        $data['stores'] = [$store->getId()];
                        $viewImages[$uniqIndex] = $data;
                    }else if(!in_array($store->getId(), $viewImages[$uniqIndex]['stores'])){
                        $viewImages[$uniqIndex]['stores'][] = $store->getId();
                    }
                }
            }
        }

        return $viewImages;
    }

    /**
     * Get unique image index.
     *
     * @param array $imageData
     * @return string
     */
    protected function getUniqueImageIndex(array $imageData): string
    {
        ksort($imageData);
        unset($imageData['type']);
        // phpcs:disable Magento2.Security.InsecureFunction
        return md5(json_encode($imageData));
    }
}
