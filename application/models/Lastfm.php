<?php

/**
 * Lastfm
 *
 * @package Amuzi
 * @version 1.0
 * Amuzi - Online music
 * Copyright (C) 2010-2013  Diogo Oliveira de Melo
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
class Lastfm extends DZend_Model
{
    private $_baseUrl = 'http://ws.audioscrobbler.com/2.0/';
    private $_key;
    private $_secret;
    private $_cache;

    protected function _request($args)
    {
        $args['api_key'] = $this->_key;
        foreach ($args as $key => $value)
            $final[] = $key . '='. urlencode($value);

        $url = $this->_baseUrl . '?' . implode('&', $final);
        $this->_logger->debug('Lastfm::_request - ' . $url);

        return file_get_contents($url);
    }

    protected function _calcName($artist, $musicTitle)
    {
        return "${artist} - ${musicTitle}";
    }

    protected function _getCover($track)
    {
        $sizes = array('extralarge', 'large', 'medium', 'small');
        $currentSize = null;
        $cover = '';
        $covers = $track->getElementsByTagName('image');

        for ($i = 0; $i < $covers->length; $i++) {
            $size = $covers->item($i)->attributes->getNamedItem('size')->nodeValue;
            if (null === $currentSize
                || array_search($currentSize, $sizes) > array_search($size, $sizes)
            ) {
                $currentSize = $size;
                $cover = $covers->item($i)->nodeValue;
            }
        }

        return $cover;
    }

    protected function _processResponseSearch($track)
    {
        $artist = $track->getElementsByTagName('artist')
            ->item(0)
            ->nodeValue;
        $musicTitle = $track->getElementsByTagName('name')
            ->item(0)
            ->nodeValue;
        $name = $this->_calcName($artist, $musicTitle);
        $cover = $this->_getCover($track);
        return  new LastfmEntry($name, $cover, $artist, $musicTitle);
    }

    protected function _processResponseSimilar($track)
    {
        $entry = $this->_processResponseGetTop($track);
        $this->_logger->debug(
            'Lastfm::_processResponseSimilar 0 nodeValue'
            . $track->getElementsByTagName('match')->item(0)->nodeValue
        );
        $this->_logger->debug(
            'Lastfm::_processResponseSimilar 1 nodeValue'
            . (
                $track->getElementsByTagName('match')->item(0)->nodeValue
                * 10000.0
              )
        );
        $similarity = $track->getElementsByTagName('match')
            ->item(0)
            ->nodeValue * 10000.0;
        $this->_logger->debug(
            'Lastfm::_processResponseSimilar 2 nodeValue' . ((int)$similarity)
        );
        $entry->similarity = (int) $similarity;

        return $entry;
    }

    public function _processResponseGetTop($track)
    {
        $artist = $track->getElementsByTagName('artist')
            ->item(0)
            ->getElementsByTagName('name')
            ->item(0)
            ->nodeValue;
        $musicTitle = $track->getElementsByTagName('name')
            ->item(0)
            ->nodeValue;
        $name = $this->_calcName($artist, $musicTitle);
        $cover = $this->_getCover($track);

        return new LastfmEntry($name, $cover, $artist, $musicTitle);
    }

    public function _exploreDOM($xml, $func, $limit = null)
    {
        $type = 'track';
        $resultSet = array();
        $xmlDoc = new DOMDocument();
        $i = 0;
        if ('' !== $xml) {
            $xmlDoc->loadXML($xml);
            if ($xmlDoc->getElementsByTagName('track')->length === 0) {
                $type = 'album';
            }

            foreach ($xmlDoc->getElementsByTagName($type) as $track) {
                $item = $this->$func($track);
                $item->type = $type;
                $resultSet[] = $item;

                if (null !== $limit) {
                    $i++;
                    if ($i >= $limit)
                        break;
                }
            }
        }

        return $resultSet;
    }

    public function __construct()
    {
        parent::__construct();
        $config = new Zend_Config_Ini(
            '../application/configs/application.ini',
            'production'
        );

        $this->_key = $config->lastfm->key;
        $this->_secret = $config->lastfm->secret;
        $this->_cache = Zend_Registry::get('cache');
    }

    public function searchTrack($q, $limit = 10, $offset = 1)
    {
        $keyTrack = sha1("Lastfm::searchTrack#$q");

        if (($xmlTrack = $this->_cache->load($keyTrack)) === false) {
            $args = array(
                'method' => 'track.search',
                'track' => $q
                );

            $xmlTrack = $this->_request($args);
            $this->_cache->save($xmlTrack, $keyTrack);
        }

        return $this->_exploreDOM($xmlTrack, '_processResponseSearch', $limit);
    }

    public function searchAlbum($q, $limit = 10, $offset = 1)
    {
        $keyAlbum = sha1("Lastfm::searchAlbum#$q");

        if (($xmlAlbum = $this->_cache->load($keyAlbum)) === false) {
            $args = array(
                'method' => 'album.search',
                'album' => $q
            );
            $xmlAlbum = $this->_request($args);
            $this->_cache->save($xmlAlbum, $keyAlbum);
        }

        return $this->_exploreDOM($xmlAlbum, '_processResponseSearch', $limit);
    }

    public function search($q, $limit = 10, $offset = 1)
    {
        return array_merge(
            $this->searchTrack($q, $limit / 2),
            $this->searchAlbum($q, $limit / 2)
        );
    }

    public function getAlbum($album, $artist)
    {
        $key = sha1('Lastfm::getAlbum' . $album . $artist);

        if (($xml = $this->_cache->load($key)) === false) {
            $args = array(
                'method' => 'album.getInfo',
                'album' => $album,
                'artist' => $artist,
                'autocorrect' => 0
            );
            $xml = $this->_request($args);
            $this->_cache->save($xml, $key);
        }

        $albumName = $artist = $cover = '';
        $xmlDoc = new DOMDocument();
        if ('' !== $xml) {
            $xmlDoc->loadXML($xml);
            $album = $xmlDoc->getElementsByTagName('album');
            for ($i = 0; $i < $album->length; $i++) {
                $value = $album->item($i)->nodeValue;
                switch ($album->item($i)->nodeName) {
                    case 'name':
                        $albumName = $value;
                        break;
                    case 'artist':
                        $artist = $value;
                        break;
                    case 'image':
                        $cover = $value;
                        break;
                }
            }
        }

        $trackList = $this->_exploreDOM($xml, '_processResponseSearch', 1000);

        $albumRow = new LastfmAlbum($albumName, $cover, $artist, $trackList);

        $this->_logger->debug('Lastfm::getAlbum - ' . $albumRow);

        return $albumRow;
    }

    public function getSimilar($artist, $music)
    {
        $key = sha1("Lastfm::search#$artist#$music");

        $this->_logger->debug('Lastfm::getSimilar A ' . microtime(true));
        if (($xml = $this->_cache->load($key)) === false) {
            $resultSet = array();
            $args = array(
                'method' => 'track.getsimilar',
                'artist' => $artist,
                'track' => $music,
                );

            $xml = $this->_request($args);
            $this->_cache->save($xml, $key);
            $this->_logger->debug('Lastfm::getSimilar B ' . microtime(true));
        }
        $this->_logger->debug('Lastfm::getSimilar C ' . microtime(true));

        return $this->_exploreDOM($xml, '_processResponseSimilar', 200);
    }

    public function getTop($limit = 50)
    {
        $date = date('Ymd');
        $key = sha1("Lastfm::getTop#$limit#$date");

        if (($xml = $this->_cache->load($key)) === false) {
            $resultSet = array();
            $args = array(
                'method' => 'geo.gettoptracks',
                'country' => 'united states'
            );

            $xml = $this->_request($args);
            $this->_logger->debug("Lastfm::getTop -> xml: " . $xml);
            $this->_cache->save($xml, $key);
        }

        return $this->_exploreDOM($xml, '_processResponseGetTop', $limit);
    }
}
