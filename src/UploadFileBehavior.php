<?php

namespace exocet\yii2UploadFileBehavior;

use Closure;
use yii;
use yii\base\Behavior;
use yii\base\ErrorException;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\imagine\Image;

/**
 * Behavior to manage the uploading of files and/or images.
 * Handles the processing and storage of files associated with an ActiveRecord model.
 *
 * ```php
 * // add to class
 * public $upload_file;
 *...
 *
 * // add to rules()
 * [['image_path'], 'safe'],
 *...
 *
 * // add to behaviors()
 *  'uploadFileBehavior' => [
 *      'class' => UploadFileBehavior::className(),
 *      'nameOfAttributeStorage' => 'image_path',
 *      'steps' => [
 *          [
 *              'path' => function($model) {
 *                  return \Yii::getAlias('@imagesPath/' . $model['id'] . '/big/');
 *              },
 *              'handler' => function($tmpFile, $newFile) {
 *                  Image::thumbnail($tmpFile, 100, 100 * 3/2)
 *                      ->copy()
 *                      ->crop(new Point(0, 0), new Box(900, 900 * 3/2))
 *                      ->save($newFile, ['quality' => 80]);
 *              },
 *          ],
 *          [
 *              'path' => '@imagesPath',
 *              'newFileName' => 'user-avatar',
 *              'handler' => [
 *                  'size' => [
 *                      'width' => 400 * 3/2, // aspect ratio 3:2
 *                      'height'=> 400,
 *                  ],
 *                  'quality' => 80,
 *                  'thumbnailSize' => [
 *                      'width' => 100 * 3/2,
 *                      'height'=> 100,
 *                  ],
 *                  'thumbnailQuality' => 70,
 *                  'saveOriginal' => true,
 *              ],
 *          ],
 *      ],
 *  ],
 * ```
 */
class UploadFileBehavior extends Behavior
{
    /** @var string Model's attribute to receive the file from the form */
    public $modelAttributeForFile = 'upload_file';

    /** @var string Model's attribute to store the file path or reference */
    public $modelAttributeForStorage = 'images';

    /** @var bool|string New filename to save the uploaded file as, defaults to false (no rename) */
    public $newFileName = false;

    /** @var array Configurations for where and how to save the uploaded file */
    public $steps;

    /** @var string Use this prefix for generated thumbnails */
    public $thumbnailPrefix = 'thumb-';

    /** @var string Use this prefix for copy original image */
    public $originalPrefix = 'original-';

    /** @var array Scenarios under which this behavior will be triggered */
    public $scenarios = ['default'];

    /** @var bool Flag to delete the file when the associated record is deleted */
    public $deleteImageWithRecord = false;

    /** @var bool Flag to delete the upload directory when update files */
    public $cleanDirWithUpdate = false;

    /** @var UploadedFile|null Instance of the uploaded file */
    private $fileInstance;

    /**
     * Initializes the behavior by checking dependencies and setting required properties.
     * Throws exceptions in case of missing dependencies or misconfiguration.
     * @throws NotSupportedException if the required extensions are not installed.
     * @throws InvalidConfigException if necessary configurations are missing or misconfigured.
     */
    public function init()
    {
        parent::init();

        if (!class_exists(Image::class)) {
            throw new NotSupportedException(Yii::t('app', "Yii2-imagine extension is required to use the UploadImageBehavior"));
        }

        if (empty($this->steps)) {
            throw new InvalidConfigException(Yii::t('app', 'The "steps" property must be set.'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function attach($owner)
    {
        parent::attach($owner);
        $this->setFileInstance();
    }

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => 'prepareFileForUpload',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'prepareFileForUpload',
            ActiveRecord::EVENT_AFTER_INSERT => 'handleAfterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'handleAfterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'handleAfterDelete'
        ];
    }

    /**
     * Prepares the file for upload before the ActiveRecord is saved.
     */
    public function prepareFileForUpload()
    {
        if ($this->isFilePresent()) {
            // add new virtual attributes
            $this->owner->{$this->modelAttributeForFile} = $this->getFileInstance();
            $this->owner->{$this->modelAttributeForStorage} = $this->generateNewFileName();
        }
    }

    /**
     * Executes after a record has been successfully inserted.
     * Invokes the upload process for a new record.
     */
    public function handleAfterInsert()
    {
        $this->processUpload(true);
    }

    /**
     * Handles the operations needed after a record is inserted.
     * Specifically, processes the file uploads for a new record.
     */
    public function handleAfterUpdate()
    {
        $this->processUpload(false);
    }

    /**
     * Handles the operations needed after a record is deleted.
     * If the deleteImageWithRecord property is set to true, associated files
     * will be deleted as well.
     */
    public function handleAfterDelete()
    {
        if ($this->deleteImageWithRecord) {
            foreach ($this->steps as $target) {
                $directoryPath = $this->resolvePath($target['path']);

                // Check if directory path is not root and exists
                if ($directoryPath !== '/' && is_dir($directoryPath)) {
                    FileHelper::removeDirectory($directoryPath);
                }
            }
        }
    }

    /**
     * Processes the upload based on whether the record is new or existing.
     * Ensures that the file is present and the scenario is allowed before uploading.
     * @param bool $isNewRecord Flag indicating whether the record is new or existing.
     * @throws ErrorException
     * @throws Exception
     * @throws InvalidConfigException
     */
    protected function processUpload($isNewRecord)
    {
        if ($this->isFilePresent() && $this->isScenarioAllowed()) {
            foreach ($this->steps as $target) {
                // Resolve the target path for storing the file and create directory
                $directoryPath = $this->resolvePath($target['path']);
                $this->prepareDirectory($directoryPath, $isNewRecord);

                // Generate the new file
                if (isset($target['handler'])) {
                    $this->executeHandler($target['handler'], $directoryPath);
                } else {
                    $this->saveFile($directoryPath);
                }
            }
        }
    }

    /**
     * Prepares directory for file storage, creating or clearing as necessary.
     * @param string $directoryPath Path to the directory.
     * @param bool $isNewRecord Whether the current operation is for a new record.
     * @throws Exception
     * @throws ErrorException
     */
    protected function prepareDirectory($directoryPath, $isNewRecord)
    {
        if (!$isNewRecord && $this->cleanDirWithUpdate) {
            FileHelper::removeDirectory($directoryPath);
        }

        FileHelper::createDirectory($directoryPath);
    }

    /**
     * Determines if an uploaded file is present for processing.
     * @return bool Whether an uploaded file is present.
     */
    protected function isFilePresent()
    {
        return $this->getFileInstance() && $this->getFileInstance()->tempName;
    }

    /**
     * Checks if the current model's scenario is among those allowed by the behavior.
     * @return bool Whether the current scenario is allowed.
     */
    protected function isScenarioAllowed()
    {
        return in_array($this->owner->scenario, $this->scenarios);
    }

    /**
     * Generates a new filename for the uploaded file, either renaming it or using a timestamp.
     * @return string The generated filename.
     */
    protected function generateNewFileName()
    {
        $file = $this->getFileInstance();
        if ($this->newFileName) {
            return "{$this->newFileName}.{$file->extension}";
        }

        $timestamp = uniqid(microtime(true), true);
        return "{$file->baseName}_{$timestamp}.{$file->extension}";
    }

    /**
     * Get already generated file name.
     * @return string The generated filename.
     */
    protected function getNewFileName()
    {
        return $this->owner->{$this->modelAttributeForStorage};
    }

    /**
     * Resolves the storage path for the uploaded file.
     * @param mixed $pathDefinition Either a path string or a Closure that defines the path.
     * @return string The resolved storage path.
     * @throws InvalidConfigException
     */
    protected function resolvePath($pathDefinition)
    {
        if (is_string($pathDefinition)) {
            return rtrim(Yii::getAlias($pathDefinition), '/') . '/';
        } elseif ($pathDefinition instanceof Closure) {
            return call_user_func($pathDefinition, $this->owner->attributes);
        }

        throw new InvalidConfigException(Yii::t('app', 'Param `path` mast be string instanceof Closure or callable method.'));
    }

    /**
     * Processes and saves an image based on the provided dimensions and quality.
     *
     * @param string $tmpFile Temporary file path of the image.
     * @param string $destination Destination path to save the processed image.
     * @param array  $dimensions Width and height for the image processing.
     * @param int    $quality Quality for the processed image.
     */
    protected function processImage($tmpFile, $destination, $dimensions, $quality)
    {
        Image::resize($tmpFile, $dimensions['width'], $dimensions['height'])
            ->save($destination, ['quality' => $quality]);
    }

    /**
     * Processes and saves a thumbnail based on the provided dimensions and quality.
     *
     * @param string $tmpFile Temporary file path of the image.
     * @param string $folder  Destination folder to save the processed image.
     * @param array  $dimensions Width and height for the image processing.
     * @param int    $quality Quality for the processed image.
     */
    protected function processThumbnail($tmpFile, $folder, $dimensions, $quality)
    {
        $newFilePath = $this->getNewFileName();
        $destination = "{$folder}{$this->thumbnailPrefix}{$newFilePath}";
        Image::thumbnail($tmpFile, $dimensions['width'], $dimensions['height'])
            ->save($destination, ['quality' => $quality]);
    }

    /**
     * Executes the file handling logic as defined by the handler.
     * @param Closure|array $handler The Closure defining file handling logic.
     * @param string $folder The storage destination folder where the file should be saved.
     * @throws InvalidConfigException|Exception
     */
    protected function executeHandler($handler, $folder)
    {
        $newFileName = $this->getNewFileName();
        $tmpFile = $this->getFileInstance()->tempName;
        $destination= "{$folder}/{$newFileName}";

        if ($handler instanceof Closure) {
            call_user_func($handler, $tmpFile, $destination, $folder);
        } else if (is_array($handler)) {
            $this->processImage($tmpFile, $destination, $handler['size'], $handler['quality']);

            if (isset($handler['thumbnailSize'])) {
                $quality = isset($handler['thumbnailQuality']) ? $handler['thumbnailQuality'] : $handler['quality'];
                $this->processThumbnail($tmpFile, $folder, $handler['thumbnailSize'], $quality);
            }

            if (isset($handler['saveOriginal']) && $handler['saveOriginal']) {
                $originalFilePath = "{$folder}/{$this->originalPrefix}{$newFileName}";
                $this->saveFile($originalFilePath);
            }
        } else {
            throw new InvalidConfigException(Yii::t('app', 'Handler must be an instance of Closure or array with configs.'));    
        }
    }

    /**
     * Executes the file save logic.
     * @param string $folder The storage destination folder where the file should be saved.
     * @throws Exception
     */
    protected function saveFile($folder)
    {
        $newFilePath = $folder . $this->getNewFileName();
        if (!$this->getFileInstance()->saveAs($newFilePath)) {
            throw new Exception(Yii::t('app', 'Error saving file.'));
        }
    }

    /**
     * Sets the file instance for the uploaded file.
     */
    protected function setFileInstance()
    {
        $this->fileInstance = UploadedFile::getInstance($this->owner, $this->modelAttributeForFile);
    }

    /**
     * Retrieves the file instance for the uploaded file.
     * @return null|UploadedFile The file instance.
     */
    protected function getFileInstance()
    {
        return $this->fileInstance;
    }
}
