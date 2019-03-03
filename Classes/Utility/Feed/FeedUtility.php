<?php

namespace Socialstream\SocialStream\Utility\Feed;


/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\DuplicationBehavior;

/**
 * FeedUtility
 */
class FeedUtility extends \Socialstream\SocialStream\Utility\BaseUtility
{
    /**
     * Emoji begin string, to be searched and replaced (must be 8 chars long!)
     *
     * @var array
     */
    public $clearStrings = array('\ud83c\u', '\ud83d\u', '\ud83e\u', '\u2600\u');

    /** @var \TYPO3\CMS\Core\Log\Logger */
    protected $logger;

    /**
     * __construct
     */
    public function __construct($pid = 0)
    {
        $this->logger = GeneralUtility::makeInstance('TYPO3\CMS\Core\Log\LogManager')->getLogger(__CLASS__);

        if ($pid) {
            $this->initTSFE($pid, 0);
            $this->initSettings();
        }
    }

    public static function initTSFE($id = 1, $typeNum = 0)
    {
        parent::initTSFE($id, $typeNum);
    }

    public function initSettings()
    {
        parent::initSettings();
    }

    /**
     * @param $type
     * @param int $pid
     * @return mixed
     */
    public static function getUtility($type, $pid = 0)
    {
        $classname = "\\Socialstream\\SocialStream\\Utility\\Feed\\" . ucfirst($type) . "Utility";
        return new $classname($pid);
    }

    /**
     * @param $url
     * @return mixed
     */
    public function getElems($url)
    {
        $elems = GeneralUtility::getUrl($url);
        $elems = $this->clearString($elems);
        return json_decode($elems);
    }

    /**
     * @param string $url
     * @return mixed
     * @throws \Exception
     */
    public function getElemsCurl($url)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Cache-Control: no-cache"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            throw new \Exception("cURL Error #:" . $err);
        } else {
            return json_decode($response);
        }
    }

    /**
     * @param $string
     * @param int $maxTitleLength
     * @return array
     */
    function generateTitleAndBody($string, $maxTitleLength = 50)
    {
        // Init returning variable
        $titleAndBody = [];
        $titleAndBody[0] = ''; // The title
        $titleAndBody[1] = ''; // The body

        // Clean up the string
        $string = trim($string);

        // Split string by newlines
        $lines = explode("\n", $string);
        $possibleTitle = $lines[0];

        if (strlen($possibleTitle) > $maxTitleLength) {
            // Split string into sentences and take first sentence
            $sentences = preg_split('/(?<=[.?!])\s+(?=[a-z])/i', $possibleTitle);

            // Remove repeated ending punctuation (!?) from first sentence (possible title)
            $titleAndBody[0] = preg_replace('/[\s?!]*(?=[\s?!]$)/', '$1', $sentences[0]);

            if (strlen($titleAndBody[0]) > $maxTitleLength) {
                // Title is still too long, it will be cropped so it will be repeated inside body
                $titleSelfExplainatory = false;

                // Title (first sentence) is still too long, truncate it
                $stringCut = substr($titleAndBody[0], 0, $maxTitleLength);
                $endPoint = strrpos($stringCut, ' ');

                // if the string doesn't contain any space then it will cut without word basis.
                $titleAndBody[0] = $endPoint ? substr($stringCut, 0, $endPoint) : substr($stringCut, 0);
                $titleAndBody[0] .= '…';
            } else {
                $titleSelfExplainatory = true;
            }

            foreach ($sentences as $key => $sentence) {
                // Don't include title in the body if it is self explainatory
                if ($key === 0 && $titleSelfExplainatory) { continue; }

                $titleAndBody[1] .= trim($sentence) . ' ';
            }
            $titleAndBody[1] = str_replace("\n", "<br/>", $titleAndBody[1]);
            $titleAndBody[1] = str_replace("<br/><br/>", "<br/>", $titleAndBody[1]);
        } else {
            // Short string, use it for title (empty body)
            $titleAndBody[0] = $possibleTitle;
        }

        // Add other lines (skipping the first one) to the body
        foreach ($lines as $key => $line) {
            // Don't include first line (title)
            if ($key === 0) { continue; }
            $titleAndBody[1] .= trim($line) . "\n";
        }
        // Trim body to remove last newline
        $titleAndBody[1] = trim($titleAndBody[1]);

        return $titleAndBody;
    }

    /**
     * @param string $string
     * @return string
     */
    public function cleanUpPostText($string = '')
    {
        // Remove multiple "new line"
        $string = preg_replace('/(\n)+/', "\n", $string);
        // Remove multiple "vertical" dots
        $string = preg_replace('/(\n\.)+/', '', $string);
        // Separate attached hashtags
        $string = preg_replace('/(\w)#/', '$1 #', $string);

        return $string;
    }

    /**
     * @param $elems
     * @return mixed
     */
    public function clearString($elems)
    {
        foreach ($this->clearStrings as $str) {
            while (strpos($elems, $str) !== false) {
                $pos = strpos($elems, $str);
                $elems = substr_replace($elems, '', $pos, 12);
            }
        }
        return $elems;
    }

    /**
     * @return mixed
     */
    protected function getStorage()
    {
        $storageRepository = $this->objectManager->get('TYPO3\\CMS\\Core\\Resource\\StorageRepository');
        return $storageRepository->findByUid($this->settings["storage"]);
    }

    /**
     * @return mixed
     */
    protected function getMainFolder()
    {
        $storage = $this->getStorage();
        if ($storage->hasFolder($this->settings["folder"])) {
            $folder = $storage->getFolder($this->settings["folder"]);
        } else {
            $folder = $storage->createFolder($this->settings["folder"]);
        }
        return $folder;
    }

    /**
     * @param \TYPO3\CMS\Core\Resource\Folder $folder
     * @param $folderName
     * @return \TYPO3\CMS\Core\Resource\Folder
     */
    protected function getSubFolder(\TYPO3\CMS\Core\Resource\Folder $folder, $folderName)
    {
        if ($folder->hasFolder($folderName)) {
            $subFolder = $folder->getSubfolder($folderName);
        } else {
            $subFolder = $folder->createFolder($folderName);
        }
        return $subFolder;
    }

    /**
     * @param \Socialstream\SocialStream\Domain\Model\Channel $channel
     * @param $imageUrl
     */
    protected function processChannelMedia(\Socialstream\SocialStream\Domain\Model\Channel $channel, $imageUrl)
    {
        $folder = $this->getSubFolder($this->getSubFolder($this->getMainFolder(), $channel->getType()), $channel->getObjectId());
        $this->processMedia($channel, "tx_socialstream_domain_model_channel", "image", $folder, $imageUrl);
    }

    /**
     * @param \Socialstream\SocialStream\Domain\Model\News $news
     * @param $imageUrl
     */
    protected function processNewsMedia(\Socialstream\SocialStream\Domain\Model\News $news, $imageUrl)
    {
        $folder = $this->getSubFolder($this->getSubFolder($this->getSubFolder($this->getMainFolder(), $news->getChannel()->getType()), $news->getChannel()->getObjectId()), "news");
        $this->processMedia($news, "tx_news_domain_model_news", "fal_media", $folder, $imageUrl);
    }

    function getExtensionFromMimeType($mime)
    {
        $all_mimes = '{"png":["image\/png","image\/x-png"],"bmp":["image\/bmp","image\/x-bmp","image\/x-bitmap","image\/x-xbitmap","image\/x-win-bitmap","image\/x-windows-bmp","image\/ms-bmp","image\/x-ms-bmp","application\/bmp","application\/x-bmp","application\/x-win-bitmap"],"gif":["image\/gif"],"jpeg":["image\/jpeg","image\/pjpeg"],"xspf":["application\/xspf+xml"],"vlc":["application\/videolan"],"wmv":["video\/x-ms-wmv","video\/x-ms-asf"],"au":["audio\/x-au"],"ac3":["audio\/ac3"],"flac":["audio\/x-flac"],"ogg":["audio\/ogg","video\/ogg","application\/ogg"],"kmz":["application\/vnd.google-earth.kmz"],"kml":["application\/vnd.google-earth.kml+xml"],"rtx":["text\/richtext"],"rtf":["text\/rtf"],"jar":["application\/java-archive","application\/x-java-application","application\/x-jar"],"zip":["application\/x-zip","application\/zip","application\/x-zip-compressed","application\/s-compressed","multipart\/x-zip"],"7zip":["application\/x-compressed"],"xml":["application\/xml","text\/xml"],"svg":["image\/svg+xml"],"3g2":["video\/3gpp2"],"3gp":["video\/3gp","video\/3gpp"],"mp4":["video\/mp4"],"m4a":["audio\/x-m4a"],"f4v":["video\/x-f4v"],"flv":["video\/x-flv"],"webm":["video\/webm"],"aac":["audio\/x-acc"],"m4u":["application\/vnd.mpegurl"],"pdf":["application\/pdf","application\/octet-stream"],"pptx":["application\/vnd.openxmlformats-officedocument.presentationml.presentation"],"ppt":["application\/powerpoint","application\/vnd.ms-powerpoint","application\/vnd.ms-office","application\/msword"],"docx":["application\/vnd.openxmlformats-officedocument.wordprocessingml.document"],"xlsx":["application\/vnd.openxmlformats-officedocument.spreadsheetml.sheet","application\/vnd.ms-excel"],"xl":["application\/excel"],"xls":["application\/msexcel","application\/x-msexcel","application\/x-ms-excel","application\/x-excel","application\/x-dos_ms_excel","application\/xls","application\/x-xls"],"xsl":["text\/xsl"],"mpeg":["video\/mpeg"],"mov":["video\/quicktime"],"avi":["video\/x-msvideo","video\/msvideo","video\/avi","application\/x-troff-msvideo"],"movie":["video\/x-sgi-movie"],"log":["text\/x-log"],"txt":["text\/plain"],"css":["text\/css"],"html":["text\/html"],"wav":["audio\/x-wav","audio\/wave","audio\/wav"],"xhtml":["application\/xhtml+xml"],"tar":["application\/x-tar"],"tgz":["application\/x-gzip-compressed"],"psd":["application\/x-photoshop","image\/vnd.adobe.photoshop"],"exe":["application\/x-msdownload"],"js":["application\/x-javascript"],"mp3":["audio\/mpeg","audio\/mpg","audio\/mpeg3","audio\/mp3"],"rar":["application\/x-rar","application\/rar","application\/x-rar-compressed"],"gzip":["application\/x-gzip"],"hqx":["application\/mac-binhex40","application\/mac-binhex","application\/x-binhex40","application\/x-mac-binhex40"],"cpt":["application\/mac-compactpro"],"bin":["application\/macbinary","application\/mac-binary","application\/x-binary","application\/x-macbinary"],"oda":["application\/oda"],"ai":["application\/postscript"],"smil":["application\/smil"],"mif":["application\/vnd.mif"],"wbxml":["application\/wbxml"],"wmlc":["application\/wmlc"],"dcr":["application\/x-director"],"dvi":["application\/x-dvi"],"gtar":["application\/x-gtar"],"php":["application\/x-httpd-php","application\/php","application\/x-php","text\/php","text\/x-php","application\/x-httpd-php-source"],"swf":["application\/x-shockwave-flash"],"sit":["application\/x-stuffit"],"z":["application\/x-compress"],"mid":["audio\/midi"],"aif":["audio\/x-aiff","audio\/aiff"],"ram":["audio\/x-pn-realaudio"],"rpm":["audio\/x-pn-realaudio-plugin"],"ra":["audio\/x-realaudio"],"rv":["video\/vnd.rn-realvideo"],"jp2":["image\/jp2","video\/mj2","image\/jpx","image\/jpm"],"tiff":["image\/tiff"],"eml":["message\/rfc822"],"pem":["application\/x-x509-user-cert","application\/x-pem-file"],"p10":["application\/x-pkcs10","application\/pkcs10"],"p12":["application\/x-pkcs12"],"p7a":["application\/x-pkcs7-signature"],"p7c":["application\/pkcs7-mime","application\/x-pkcs7-mime"],"p7r":["application\/x-pkcs7-certreqresp"],"p7s":["application\/pkcs7-signature"],"crt":["application\/x-x509-ca-cert","application\/pkix-cert"],"crl":["application\/pkix-crl","application\/pkcs-crl"],"pgp":["application\/pgp"],"gpg":["application\/gpg-keys"],"rsa":["application\/x-pkcs7"],"ics":["text\/calendar"],"zsh":["text\/x-scriptzsh"],"cdr":["application\/cdr","application\/coreldraw","application\/x-cdr","application\/x-coreldraw","image\/cdr","image\/x-cdr","zz-application\/zz-winassoc-cdr"],"wma":["audio\/x-ms-wma"],"vcf":["text\/x-vcard"],"srt":["text\/srt"],"vtt":["text\/vtt"],"ico":["image\/x-icon","image\/x-ico","image\/vnd.microsoft.icon"],"csv":["text\/x-comma-separated-values","text\/comma-separated-values","application\/vnd.msexcel"],"json":["application\/json","text\/json"]}';
        $all_mimes = json_decode($all_mimes, true);
        foreach ($all_mimes as $key => $value) {
            if (array_search($mime, $value) !== false) return $key;
        }
        return false;
    }

    /**
     * @param string $url
     * @param $model
     * @return array
     */
    function grabImage($url, $model)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Cache-Control: no-cache"
            ),
        ));

        $response = curl_exec($curl);
        $requestInfo = curl_getinfo($curl);

        curl_close($curl);

        $imageName = null;
        $image = null;
        if ($requestInfo["http_code"] == 200) {
            $image = $response;
            $urlPath = parse_url($url)['path'];
            if (substr($urlPath, -4) == '.php') {
                $imageName = $model->getObjectId() . "." . $this->getExtensionFromMimeType(curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
            } else {
                $imageName = pathinfo($urlPath)['basename'];
            }
        } else {
            $this->logger->warning(
                'Error getting post image',
                [
                    'curl_url' => $requestInfo['url'],
                    'curl_response' => $response,
                    'curl_http_code' => $requestInfo['http_code'],
                    'curl_content_type' => $requestInfo['content_type'],
                    'curl_download_content_length' => $requestInfo['download_content_length'],
                    'curl_size_download' => $requestInfo['size_download'],
                ]
            );
        }

        return [
            'imageName' => $imageName,
            'image' => $image
        ];
    }

    /**
     * @param $model
     * @param $table
     * @param $field
     * @param Folder $folder
     * @param $imageUrl
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
     */
    protected function processMedia($model, $table, $field, $folder, $imageUrl)
    {
        $imageArray = $this->grabImage($imageUrl, $model);
        if (!isset($imageArray['image']) || !isset($imageArray['imageName'])) { return; }

        /**
         * Save the image, eventually replace if already exists
         */
        $imageName = $imageArray['imageName'];

        if (file_exists($this->settings["tmp"] . $imageName)) {
            unlink($this->settings["tmp"] . $imageName);
        }
        $fp = fopen($this->settings["tmp"] . $imageName, 'x');
        fwrite($fp, $imageArray['image']);
        fclose($fp);

        /** @var \TYPO3\CMS\Core\Resource\ResourceStorage $storage */
        $storage = $this->getStorage();

        /** @var \TYPO3\CMS\Core\Resource\File $importedImage */
        $importedImage = $storage->addFile(
            $this->settings["tmp"] . $imageName,
            $folder,
            $imageName,
            DuplicationBehavior::REPLACE,
            true
        );

        $importedImageUid = $importedImage->getUid();

        /**
         * Delete existing relation
         */
        $command = [];
        $modelUid = $model->getUid();

        /** @var \TYPO3\CMS\Core\Database\Connection $connectionFileReference */
        $connectionFileReference = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $queryBuilderFileReference = $connectionFileReference->createQueryBuilder();
        $queryBuilderFileReference->getRestrictions()->removeAll();
        $statementFileReference = $queryBuilderFileReference->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilderFileReference->expr()->eq('tablenames', $queryBuilderFileReference->createNamedParameter($table, \PDO::PARAM_STR)),
                $queryBuilderFileReference->expr()->eq('fieldname', $queryBuilderFileReference->createNamedParameter($field, \PDO::PARAM_STR)),
                $queryBuilderFileReference->expr()->eq('uid_foreign', $queryBuilderFileReference->createNamedParameter($modelUid, \PDO::PARAM_INT)),
                $queryBuilderFileReference->expr()->eq('uid_local', $queryBuilderFileReference->createNamedParameter($importedImageUid, \PDO::PARAM_INT))
            )
            ->execute();

        /** @noinspection PhpAssignmentInConditionInspection */
        while ($referenceRecord = $statementFileReference->fetch()) {
            $command['sys_file_reference'][$referenceRecord['uid']] = [ 'delete' => 1 ];
        }

        /**
         * Add new relation
         */
        $data = [];
        $newId = 'NEW' . $importedImageUid;
        $data['sys_file_reference'][$newId] = [
            'table_local' => 'sys_file',
            'uid_local' => $importedImageUid,
            'tablenames' => $table,
            'uid_foreign' => $modelUid,
            'fieldname' => $field,
            'pid' => (int)$this->settings["storagePid"],
            'showinpreview' => 1,
        ];
        $data[$table][$modelUid] = [
            $field => $newId
        ];

        /** @var DataHandler $dataHandler */
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $command);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
    }

    /**
     * @param $path
     * @return bool
     */
    protected function exists($path)
    {
        return (@fopen($path, "r") == true);
    }

    /**
     * @param $url
     * @param $saveto
     */
    protected function grab_image($url, $saveto)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (file_exists($saveto)) {
            unlink($saveto);
        }
        $fp = fopen($saveto, 'x');
        fwrite($fp, $raw);
        fclose($fp);
    }

    /**
     * @param \Socialstream\SocialStream\Domain\Model\Channel $channel
     * @param $sysmail
     * @param string $sendermail
     */
    public function sendTokenInfoMail(\Socialstream\SocialStream\Domain\Model\Channel $channel, $sysmail, $sendermail = "")
    {
        //$this->uriBuilder->reset();
        //$this->uriBuilder->setCreateAbsoluteUri(1);
        //$url = explode("?",$this->uriBuilder->buildBackendUri())[0];
        $uriBuilder = $this->objectManager->get('TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder');
        $uriBuilder->initializeObject();
        $uriBuilder->setCreateAbsoluteUri(1);
        $url = explode("?", $uriBuilder->buildBackendUri())[0];
        if (substr($url, 0, 1) === "/") {
            if ($_SERVER["HTTP_HOST"]) {
                $url = "http://" . $_SERVER["HTTP_HOST"] . $url;
            } else {
                $url = "http://" . $_SERVER["HOSTNAME"] . $url;
            }
        }

        $subject = "Social Stream - " . \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('labels.subject_token', 'social_stream');
        $text = "Social Stream " . $this->getType($channel->getType()) . " " . $channel->getTitle() . ": <br/>";
        $text .= \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('labels.body_token', 'social_stream');
        $text .= "<br/><br/><a href='" . $url . "'>" . $url . "</a>";

        if (!$sendermail) $sendermail = "no-reply@example.com";
        $this->sendInfoMail(array($sendermail => "Social Stream"), array($sysmail => $sysmail), $subject, $text);
    }

    /**
     * @param $txt
     * @param $head
     * @param $type
     * @param $obj
     */
    public function addFlashMessage($txt, $head, $type, $obj)
    {
        $message = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessage', $txt, $head, $type, TRUE);
        $messageQueue = $obj->getMessageQueueByIdentifier();
        $messageQueue->addMessage($message);
    }

    /**
     * @param $type
     * @param \GeorgRinger\News\Domain\Model\Category|NULL $parent
     * @return \GeorgRinger\News\Domain\Model\Category
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException
     */
    protected function getCategory($type, \GeorgRinger\News\Domain\Model\Category $parent = NULL)
    {
        $title = $this->getType($type);

        $cat = $this->categoryRepository->findOneByTitle($title);

        if (!$cat) {
            $cat = new \GeorgRinger\News\Domain\Model\Category();
            $cat->setTitle($title);
            if ($parent) $cat->setParentcategory($parent);
            $this->categoryRepository->add($cat);
            $this->persistenceManager->persistAll();
        }
        return $cat;
    }

}