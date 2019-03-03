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

use \TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use \TYPO3\CMS\Core\Messaging\AbstractMessage;
use \TYPO3\CMS\Core\Messaging\FlashMessageService;
use \TYPO3\CMS\Core\Messaging\FlashMessage;
use \TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * FacebookUtility
 */
class FacebookUtility extends \Socialstream\SocialStream\Utility\Feed\FeedUtility
{

    public function getChannel(\Socialstream\SocialStream\Domain\Model\Channel $channel, $isProcessing = 0)
    {
        $url = "https://graph.facebook.com/" . $channel->getObjectId() . "/?fields=id,name,about,link,picture,cover&access_token=" . $channel->getToken();

        if ($this->get_http_response_code($url) == 200) {
            $elem = $this->getElems($url);

            $channel->setObjectId($elem->id);
            $channel->setTitle($elem->name);
            if ($elem->about) $channel->setAbout($elem->about);
            //if($elem->description)$channel->setDescription($elem->description);
            $channel->setLink($elem->link);

            if ($isProcessing == 0) {
                $picStream = json_decode(file_get_contents("https://graph.facebook.com/" . $channel->getObjectId() . "/picture?redirect=0&width=900&access_token=" . $channel->getToken()));
                $imageUrl = $picStream->data->url;
                if ($this->exists($imageUrl)) {
                    $this->processChannelMedia($channel, $imageUrl);
                }
            }
        } else {
            if ($isProcessing == 0) {
                if ($this->settings["sysmail"]) {
                    $this->sendTokenInfoMail($channel, $this->settings["sysmail"], $this->settings["sendermail"]);
                }
            } else {
                $msg = "Fehler: Channel konnte nicht gecrawlt werden. Object Id oder Token falsch.";
                //$this->addFlashMessage($msg, '', AbstractMessage::ERROR);
                $this->objectManager = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Object\\ObjectManager');
                $this->addFlashMessage($msg, '', FlashMessage::ERROR, $this->objectManager->get(FlashMessageService::class));
                return false;
            }
        }

        return $channel;
    }

    public function renewToken(\Socialstream\SocialStream\Domain\Model\Channel $channel)
    {
        if ($channel->getExpires() !== 0) {
            $expdiff = ($channel->getExpires() - time()) / 86400;
            if ($expdiff <= 5) {
                $url = "https://graph.facebook.com/oauth/access_token?grant_type=fb_exchange_token&client_id=" . $this->settings["fbappid"] . "&client_secret=" . $this->settings["fbappsecret"] . "&fb_exchange_token=" . $channel->getToken();
                if ($this->get_http_response_code($url) == 200) {
                    $token = file_get_contents($url);
                    /*$infos = explode("&", $token);
                    $tk = explode("=", $infos[0])[1];
                    $exp = time() + explode("=", $infos[1])[1];*/
                    $tk = json_decode($token);
                    $channel->setToken($tk->access_token);
                    $channel->setExpires(time() + $tk->expires_in);
                } else {
                    if ($this->settings["sysmail"]) {
                        $this->sendTokenInfoMail($channel, $this->settings["sysmail"], $this->settings["sendermail"]);
                    }
                }
            }
        }
        return $channel;
    }

    public function getFeed(\Socialstream\SocialStream\Domain\Model\Channel $channel, $limit = 100)
    {
        $this->persistenceManager = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Persistence\\Generic\\PersistenceManager');
        $this->newsRepository = GeneralUtility::makeInstance('Socialstream\\SocialStream\\Domain\\Repository\\NewsRepository');
        $this->categoryRepository = GeneralUtility::makeInstance('GeorgRinger\\News\\Domain\\Repository\\CategoryRepository');

        if ($channel->getPosttype() == "1") {
            $url = "https://graph.facebook.com/" . $channel->getObjectId() . "/posts?fields=id,created_time,link,permalink_url,place,type,message,full_picture,object_id,picture,name,caption,description,story,source,from,attachments.limit(10){type,subattachments}&access_token=" . $channel->getToken() . "&limit=" . $limit . "&locale=" . $this->settings["locale"];
        } else {
            $url = "https://graph.facebook.com/" . $channel->getObjectId() . "/feed?fields=id,created_time,link,permalink_url,place,type,message,full_picture,object_id,picture,name,caption,description,story,source,from,attachments.limit(10){type,subattachments}&access_token=" . $channel->getToken() . "&limit=" . $limit . "&locale=" . $this->settings["locale"];
        }

        $elem = $this->getElems($url);

        if (!isset($elem->data)) { return; }

        foreach ($elem->data as $entry) {
            // Skip if post type not yet handled
            $subType = $this->getSubType($entry);
            $handledSubTypes = [
                'status',
                'photo',
                'album',
                'new_album',
                'video_inline',
                'profile_media',
                'cover_photo',
                'share',
                'avatar',
            ];
            if ( !in_array($subType, $handledSubTypes, true )) { continue; }

            $new = 0;
            $news = $this->newsRepository->findHiddenById($entry->id, $channel->getUid(), 1);
            if (!$news) {
                $news = new \Socialstream\SocialStream\Domain\Model\News();
                $new = 1;
            }

            $news->setType(0);
            $news->setChannel($channel);
            $cat = $this->getCategory($channel->getType());
            $news->addCategory($cat);
            $subcat = $this->getCategory($channel->getTitle(), $cat);
            $news->addCategory($subcat);
            $id = explode("_", $entry->id);
            if ($id[1]) {
                $news->setObjectId($id[1]);
            } else {
                $news->setObjectId($entry->id);
            }
            $news->setDatetime(new \DateTime($entry->created_time));
            if ($entry->link) $news->setLink($entry->link);
            $news->setAuthor($entry->from->name);

            if ($entry->place) {
                $news->setPlaceName($entry->place->name);
                $news->setPlaceCity($entry->place->location->city);
                $news->setPlaceCountry($entry->place->location->country);
                $news->setPlaceLat($entry->place->location->latitude);
                $news->setPlaceLng($entry->place->location->longitude);
                $news->setPlaceStreet($entry->place->location->street);
                $news->setPlaceZip($entry->place->location->zip);
            }

            $newsTitle = $channel->getTitle() . ' - ' . $entry->type; // Set a default title
            $newsBody = ''; // News body defaults to empty
            switch ($subType) {
                case 'profile_media':
                case 'cover_photo':
                    // Cases where we don't want to use 'name' because it's not well explaining
                    if ($entry->story) {
                        $newsTitle = $entry->story;
                    }
                    break;
                case 'status':
                case 'photo':
                case 'album':
                case 'new_album':
                case 'video_inline':
                case 'share':
                case 'avatar':
                        // We have a title in the post, use it
                        $newsTitle = $entry->name;
                        // Also set the news body
                        $newsBody = $entry->message;
                    } else {
                        // We don't have a title in the post, generate it from the post text
                        if ($entry->message) {
                            $titleAndBody = $this->generateTitleAndBody($entry->message, $this->settings["maxNewsTitleLength"]);
                            // But use default title if one can't be generated
                            $newsTitle = $titleAndBody[0] != '' ? $titleAndBody[0] : $newsTitle;
                            $newsBody = $titleAndBody[1];
                        }
                    }
                    break;
            }
            $news->setTitle($newsTitle);
            $news->setBodytext($newsBody);
            $news->setDescription($newsBody != '' ? $newsBody : $newsTitle);

            if ($new) {
                $this->newsRepository->add($news);
            } else {
                $this->newsRepository->update($news);
            }
            $this->persistenceManager->persistAll();

            $singlePost = json_decode(file_get_contents("https://graph.facebook.com/" . $entry->id . "/?fields=full_picture,source&access_token=" . $channel->getToken()));

            $imageUrl = '';
            $videoUrl = '';

            if ($entry->source) {
                $videoUrl = $entry->source;
            } else if ($singlePost->source) {
                $videoUrl = $singlePost->source;
            }
            if ($entry->full_picture) {
                $imageUrl = $entry->full_picture;
            } else if ($singlePost->full_picture) {
                $imageUrl = $singlePost->full_picture;
            }


            $media = $this->validateMedia($channel, $imageUrl, $videoUrl);


            if (is_array($media)) {
                if ($media['link']) {
                    $news->setMediaUrl($media['link']);
                }
                if ($media['media_url']) {
                    $this->processNewsMedia($news, $media['media_url']);
                }
            }

            // Get other attachments if present
            if (is_array($entry->attachments->data[0]->subattachments->data)) {
                foreach ($entry->attachments->data[0]->subattachments->data as $key => $attachment) {
                    if ($key === 0) { continue; } // Skip first attachment because it has been already imported

                    if ($attachment->type == 'photo') {
                        $imageUrl = $attachment->media->image->src;
                        $videoUrl = '';
                        $media = $this->validateMedia($channel, $imageUrl, $videoUrl);
                        if (is_array($media)) {
                            if ($media['link']) {
                                $news->setMediaUrl($media['link']);
                            }
                            if ($media['media_url']) {
                                $this->processNewsMedia($news, $media['media_url']);
                            }
                        }
                    }
                }
            }

            $this->newsRepository->update($news);
            $this->persistenceManager->persistAll();
        }
    }

    /**
     * @param $entry
     * @return mixed
     */
    private function getSubType($entry) {
        $subType = $entry->type;
        if ($entry->attachments->data[0]->type) {
            $subType = $entry->attachments->data[0]->type;
        }
        return $subType;
    }
}