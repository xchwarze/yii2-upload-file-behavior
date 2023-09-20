# UploadFileBehavior for Yii2

`UploadFileBehavior` is a Yii2 behavior designed to streamline the process of uploading files and/or images. It manages the processing and storage of files associated with an ActiveRecord model.

## Features

- File uploading for ActiveRecord models.
- Customizable file saving steps.
- Thumbnail generation.
- Option to rename uploaded files.
- Ability to delete files when associated records are deleted.
- Automatic cleanup of directories during updates.

## Installation

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

```bash
composer require "exocet/yii2-upload-file-behavior"
```

## Setup and Configuration

1. Include new behavior:
    ```php
    use exocet\yii2UploadFileBehavior\UploadFileBehavior;
    ```

2. Add the upload file attribute to your model:
    ```php
    public $upload_file;
    ```

3. Add safety rules for your image path or similar:
    ```php
    [['image_path'], 'safe'],
    ```

4. Attach the behavior to your model:
    ```php
    public function behaviors() {
        return [
            'uploadFileBehavior' => [
                'class' => UploadFileBehavior::className(),
                'nameOfAttributeStorage' => 'image_path',
                //... other configurations
            ],
        ];
    }
    ```

### Configuration Options

Here's a quick run-through of the configuration options:

- `modelAttributeForFile`: (string) The model's attribute to receive the file from the form. Defaults to `'upload_file'`.

- `modelAttributeForStorage`: (string) The model's attribute to store the file path or reference. Defaults to `'images'`.

- `newFileName`: (bool|string) A new filename to save the uploaded file as. Defaults to `false` (meaning it won't rename).

- `steps`: (array) Configurations detailing where and how to save the uploaded file. This can include thumbnail generation, different save paths, etc.

- `thumbnailPrefix`: (string) Prefix for generated thumbnails. Defaults to `'thumb-'`.

- `originalPrefix`: (string) Prefix to use when saving a copy of the original image. Defaults to `'original-'`.

- `scenarios`: (array) Scenarios under which this behavior will be triggered. Defaults to `['default']`.

- `deleteImageWithRecord`: (bool) Whether or not to delete the file when the associated record is deleted. Defaults to `false`.

- `cleanDirWithUpdate`: (bool) Whether or not to clean the upload directory when updating files. Defaults to `false`.

For more in-depth examples and how to set up the `steps` configuration, refer to the example given in the code comments.

## Contribution

Feel free to contribute to this project by opening issues, pull requests, or providing feedback. Your contributions are welcome!

---

Designed with :heart: for Yii2 developers.
