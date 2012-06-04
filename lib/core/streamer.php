<?php
/***********************************************
* File      :   streamer.php
* Project   :   Z-Push
* Descr     :   This file handles streaming of
*               WBXML SyncObjects. It must be
*               subclassed so the internals of
*               the object can be specified via
*               $mapping. Basically we set/read
*               the object variables of the
*               subclass according to the mappings
*
* Created   :   01.10.2007
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

class Streamer implements Serializable {
    const STREAMER_VAR = 1;
    const STREAMER_ARRAY = 2;
    const STREAMER_TYPE = 3;
    const STREAMER_PROP = 4;
    const STREAMER_TYPE_DATE = 1;
    const STREAMER_TYPE_HEX = 2;
    const STREAMER_TYPE_DATE_DASHES = 3;
    const STREAMER_TYPE_STREAM = 4;
    const STREAMER_TYPE_IGNORE = 5;
    const STREAMER_TYPE_SEND_EMPTY = 6;
    const STREAMER_TYPE_NO_CONTAINER = 7;
    const STREAMER_TYPE_COMMA_SEPARATED = 8;
    const STREAMER_TYPE_SEMICOLON_SEPARATED = 9;

    protected $mapping;
    public $flags;
    public $content;

    /**
     * Constructor
     *
     * @param array     $mapping            internal mapping of variables
     * @access public
     */
    function Streamer($mapping) {
        $this->mapping = $mapping;
        $this->flags = false;
    }

    /**
     * Decodes the WBXML from a WBXMLdecoder until we reach the same depth level of WBXML.
     * This means that if there are multiple objects at this level, then only the first is
     * decoded SubOjects are auto-instantiated and decoded using the same functionality
     *
     * @param WBXMLDecoder  $decoder
     *
     * @access public
     */
    public function Decode(&$decoder) {
        while(1) {
            $entity = $decoder->getElement();

            if($entity[EN_TYPE] == EN_TYPE_STARTTAG) {
                if(! ($entity[EN_FLAGS] & EN_FLAGS_CONTENT)) {
                    $map = $this->mapping[$entity[EN_TAG]];
                    if (isset($map[self::STREAMER_ARRAY])) {
                        $this->$map[self::STREAMER_VAR] = array();
                    } else if(!isset($map[self::STREAMER_TYPE])) {
                        $this->$map[self::STREAMER_VAR] = "";
                    }
                    else if ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE_DASHES ) {
                        $this->$map[self::STREAMER_VAR] = "";
                    }
                    else if ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_SEND_EMPTY)
                        $this->$map[self::STREAMER_VAR] = "";
                    else if (isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_SEND_EMPTY)
                        $this->$map[self::STREAMER_VAR] = "";
                    continue;
                }
                // Found a start tag
                if(!isset($this->mapping[$entity[EN_TAG]])) {
                    // This tag shouldn't be here, abort
                    ZLog::Write(LOGLEVEL_WBXMLSTACK, sprintf("Tag '%s' unexpected in type XML type '%s'", $entity[EN_TAG], get_class($this)));
                    return false;
                }
                else {
                    $map = $this->mapping[$entity[EN_TAG]];

                    // Handle an array
                    if(isset($map[self::STREAMER_ARRAY])) {
                        while(1) {
                            //do not get start tag for an array without a container
                            if (!(isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_NO_CONTAINER)) {
                                if(!$decoder->getElementStartTag($map[self::STREAMER_ARRAY]))
                                    break;
                            }
                            if(isset($map[self::STREAMER_TYPE])) {
                                $decoded = new $map[self::STREAMER_TYPE];

                                $decoded->Decode($decoder);
                            }
                            else {
                                $decoded = $decoder->getElementContent();
                            }

                            if(!isset($this->$map[self::STREAMER_VAR]))
                                $this->$map[self::STREAMER_VAR] = array($decoded);
                            else
                                array_push($this->$map[self::STREAMER_VAR], $decoded);

                            if(!$decoder->getElementEndTag()) //end tag of a container element
                                return false;

                            if (isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_NO_CONTAINER) {
                                $e = $decoder->peek();
                                //go back to the initial while if another block of no container elements is found
                                if ($e[EN_TYPE] == EN_TYPE_STARTTAG) {
                                    continue 2;
                                }
                                //break on end tag because no container elements block end is reached
                                if ($e[EN_TYPE] == EN_TYPE_ENDTAG)
                                    break;
                                if (empty($e))
                                    break;
                            }
                        }
                        //do not get end tag for an array without a container
                        if (!(isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_NO_CONTAINER)) {
                            if(!$decoder->getElementEndTag()) //end tag of container
                                return false;
                        }
                    }
                    else { // Handle single value
                        if(isset($map[self::STREAMER_TYPE])) {
                            // Complex type, decode recursively
                            if($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE_DASHES) {
                                $decoded = $this->parseDate($decoder->getElementContent());
                                if(!$decoder->getElementEndTag())
                                    return false;
                            }
                            else if($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_HEX) {
                                $decoded = hex2bin($decoder->getElementContent());
                                if(!$decoder->getElementEndTag())
                                    return false;
                            }
                            else if($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_SEND_EMPTY) {
                                $decoded = $decoder->getElementContent();
                                if(!$decoder->getElementEndTag())
                                    return false;
                            }
                            else {
                                $subdecoder = new $map[self::STREAMER_TYPE]();
                                if($subdecoder->Decode($decoder) === false)
                                    return false;

                                $decoded = $subdecoder;

                                if(!$decoder->getElementEndTag()) {
                                    ZLog::Write(LOGLEVEL_WBXMLSTACK, sprintf("No end tag for '%s'", $entity[EN_TAG]));
                                    return false;
                                }
                            }
                        }
                        else {
                            // Simple type, just get content
                            $decoded = $decoder->getElementContent();

                            if($decoded === false) {
                                // the tag is declared to have content, but no content is available.
                                // set an empty content
                                $decoded = "";
                            }

                            if(!$decoder->getElementEndTag()) {
                                ZLog::Write(LOGLEVEL_WBXMLSTACK, sprintf("Unable to get end tag for '%s'", $entity[EN_TAG]));
                                return false;
                            }
                        }
                        // $decoded now contains data object (or string)
                        $this->$map[self::STREAMER_VAR] = $decoded;
                    }
                }
            }
            else if($entity[EN_TYPE] == EN_TYPE_ENDTAG) {
                $decoder->ungetElement($entity);
                break;
            }
            else {
                ZLog::Write(LOGLEVEL_WBXMLSTACK, "Unexpected content in type");
                break;
            }
        }
    }

    /**
     * Encodes this object and any subobjects - output is ordered according to mapping
     *
     * @param WBXMLEncoder  $encoder
     *
     * @access public
     */
    public function Encode(&$encoder) {
        foreach($this->mapping as $tag => $map) {
            if(isset($this->$map[self::STREAMER_VAR])) {
                // Variable is available
                if(is_object($this->$map[self::STREAMER_VAR])) {
                    // Subobjects can do their own encoding
                    if ($this->$map[self::STREAMER_VAR] instanceof Streamer) {
                        $encoder->startTag($tag);
                        $this->$map[self::STREAMER_VAR]->Encode($encoder);
                        $encoder->endTag();
                    }
                    else
                        ZLog::Write(LOGLEVEL_ERROR, sprintf("Streamer->Encode(): parameter '%s' of object %s is not of type Streamer", $map[self::STREAMER_VAR], get_class($this)));
                }
                // Array of objects
                else if(isset($map[self::STREAMER_ARRAY])) {
                    if ((empty($this->$map[self::STREAMER_VAR]) && isset($map[self::STREAMER_TYPE]) && $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_SEND_EMPTY)
                    || (isset($map[self::STREAMER_PROP]) &&  isset($map[self::STREAMER_PROP]) == self::STREAMER_TYPE_SEND_EMPTY)) {
                        $encoder->startTag($tag, false, true);
                    }
                    else {
                        // Outputs array container (eg Attachments)
                        // Do not output start and end tag when type is STREAMER_TYPE_NO_CONTAINER
                        if (!isset($map[self::STREAMER_PROP]) || $map[self::STREAMER_PROP] != self::STREAMER_TYPE_NO_CONTAINER)
                            $encoder->startTag($tag);

                        foreach ($this->$map[self::STREAMER_VAR] as $element) {
                            if(is_object($element)) {
                                $encoder->startTag($map[self::STREAMER_ARRAY]); // Outputs object container (eg Attachment)
                                $element->Encode($encoder);
                                $encoder->endTag();
                            }
                            else {
                                if(strlen($element) == 0)
                                      // Do not output empty items. Not sure if we should output an empty tag with $encoder->startTag($map[self::STREAMER_ARRAY], false, true);
                                      ;
                                else {
                                    $encoder->startTag($map[self::STREAMER_ARRAY]);
                                    $encoder->content($element);
                                    $encoder->endTag();
                                }
                            }
                        }

                        if  (!isset($map[self::STREAMER_PROP]) || $map[self::STREAMER_PROP] != self::STREAMER_TYPE_NO_CONTAINER)
                            $encoder->endTag();
                    }
                }
                else {
                    if(isset($map[self::STREAMER_TYPE]) && $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_IGNORE) {
                        continue;
                    }

                    // Simple type
                    if(!isset($map[self::STREAMER_TYPE]) && strlen($this->$map[self::STREAMER_VAR]) == 0) {
                        if ((isset($map[self::STREAMER_TYPE]) && $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_SEND_EMPTY)
                        || (isset($map[self::STREAMER_PROP]) &&  isset($map[self::STREAMER_PROP]) == self::STREAMER_TYPE_SEND_EMPTY)) {
                            $encoder->startTag($tag, false, true);
                        }
                          // Do not output empty items. See above: $encoder->startTag($tag, false, true);
                        continue;
                    } else
                        $encoder->startTag($tag);

                    if(isset($map[self::STREAMER_TYPE]) && ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE_DASHES)) {
                        if($this->$map[self::STREAMER_VAR] != 0) // don't output 1-1-1970
                            $encoder->content($this->formatDate($this->$map[self::STREAMER_VAR], $map[self::STREAMER_TYPE]));
                    }
                    else if(isset($map[self::STREAMER_TYPE]) && $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_HEX) {
                        $encoder->content(strtoupper(bin2hex($this->$map[self::STREAMER_VAR])));
                    }
                    else if(isset($map[self::STREAMER_TYPE]) && $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_STREAM) {
                        //encode stream with base64
                        //TODO stream chunked without loading the complete attachment into memory
                        $stream = $this->$map[self::STREAMER_VAR];
                        $base64filter = stream_filter_append($stream, 'convert.base64-encode');
                        $d = "";
                        while (!feof($stream)) {
                            $d .= fgets($stream, 4096);
                        }
                        $encoder->content($d);
                        stream_filter_remove($base64filter);
                    }
                    // implode comma or semicolon arrays into a string
                    else if(isset($map[self::STREAMER_TYPE]) && is_array($this->$map[self::STREAMER_VAR]) &&
                        ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_COMMA_SEPARATED || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_SEMICOLON_SEPARATED)) {
                        $glue = ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_COMMA_SEPARATED)?", ":"; ";
                        $encoder->content(implode($glue, $this->$map[self::STREAMER_VAR]));
                    }
                    else {
                        $encoder->content($this->$map[self::STREAMER_VAR]);
                    }
                    $encoder->endTag();
                }
            }
        }
        // Output our own content
        if(isset($this->content))
            $encoder->content($this->content);
    }

    /**
     * Removes not necessary data from the object
     *
     * @access public
     * @return boolean
     */
    public function StripData() {
        foreach ($this->mapping as $k=>$v) {
            if (isset($this->$v[self::STREAMER_VAR])) {
                if (is_object($this->$v[self::STREAMER_VAR]) && method_exists($this->$v[self::STREAMER_VAR], "StripData") ) {
                    $this->$v[self::STREAMER_VAR]->StripData();
                }
                else if (isset($v[self::STREAMER_ARRAY]) && !empty($this->$v[self::STREAMER_VAR])) {
                    foreach ($this->$v[self::STREAMER_VAR] as $element) {
                        if (is_object($element) && method_exists($element, "StripData") ) {
                            $element->StripData();
                        }
                    }
                }
            }
        }
        unset($this->mapping);

        return true;
    }

    /**
     * Method to serialize a Streamer and respective SyncObject
     *
     * @access public
     * @return array
     */
    public function serialize() {
        $values = array();
        foreach ($this->mapping as $k=>$v) {
            if (isset($this->$v[self::STREAMER_VAR]))
                $values[$v[self::STREAMER_VAR]] = serialize($this->$v[self::STREAMER_VAR]);
        }

        return serialize($values);
    }

    /**
     * Method to unserialize a Streamer and respective SyncObject
     *
     * @access public
     * @return array
     */
    public function unserialize($data) {
        $class = get_class($this);
        $this->$class();
        $values = unserialize($data);
        foreach ($values as $k=>$v)
            $this->$k = unserialize($v);

        return true;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private methods for conversion
     */

    /**
     * Formats a timestamp
     * Oh yeah, this is beautiful. Exchange outputs date fields differently in calendar items
     * and emails. We could just always send one or the other, but unfortunately nokia's 'Mail for
     *  exchange' depends on this quirk. So we have to send a different date type depending on where
     * it's used. Sigh.
     *
     * @param long  $ts
     * @param int   $type
     *
     * @access private
     * @return string
     */
    private function formatDate($ts, $type) {
        if($type == self::STREAMER_TYPE_DATE)
            return gmstrftime("%Y%m%dT%H%M%SZ", $ts);
        else if($type == self::STREAMER_TYPE_DATE_DASHES)
            return gmstrftime("%Y-%m-%dT%H:%M:%S.000Z", $ts);
    }

    /**
     * Transforms an AS timestamp into a unix timestamp
     *
     * @param string    $ts
     *
     * @access private
     * @return long
     */
    function parseDate($ts) {
        if(preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})(T(\d{2})[^0-9]*(\d{2})[^0-9]*(\d{2})(.\d+)?Z){0,1}$/", $ts, $matches)) {
            if ($matches[1] >= 2038){
                $matches[1] = 2038;
                $matches[2] = 1;
                $matches[3] = 18;
                $matches[5] = $matches[6] = $matches[7] = 0;
            }

            if (!isset($matches[5])) $matches[5] = 0;
            if (!isset($matches[6])) $matches[6] = 0;
            if (!isset($matches[7])) $matches[7] = 0;

            return gmmktime($matches[5], $matches[6], $matches[7], $matches[2], $matches[3], $matches[1]);
        }
        return 0;
    }
}

?>