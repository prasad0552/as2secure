<?php

/**
 * AS2Secure - PHP Lib for AS2 message encoding / decoding
 *
 * @author  Sebastien MALOT <contact@as2secure.com>
 *
 * @copyright Copyright (c) 2010, Sebastien MALOT
 *
 * Last release at : {@link http://www.as2secure.com}
 *
 * This file is part of AS2Secure Project.
 *
 * AS2Secure is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AS2Secure is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AS2Secure.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.html GNU General Public License
 * @version 0.7.1
 *
 */

/**
 * Fix to get request headers from Apache even on PHP running as a CGI
 *
 */
if( !function_exists('apache_request_headers') ) {
    function apache_request_headers() {
        $headers = array();

        foreach($_SERVER as $key => $value){
            if (strpos('HTTP_', $key) === 0){
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5))))); 
                $headers[$key] = $value;
            }
        }

        return $headers;
    }
}

class AS2Server {
    /**
     * Handle a request (server side)
     * 
     * @param request     (If not set, get data from standard input)
     * 
     * @return request    The request handled
     */
    public static function handle($request = null)
    {
        try {
            $error = null;

            if (is_null($request)){
                // content loading
                $data = file_get_contents('php://input');
                if (!$data) throw new AS2Exception('An empty AS2 message (no content) was received, the message will be suspended.');
                
                // headers loading
                $headers = apache_request_headers();
                if (!$headers) throw new AS2Exception('An empty AS2 message (no headers) was received, the message will be suspended.');
    
                // check content of headers
                $headers_lower = array_change_key_case($headers);
                if (!in_array('message-id', array_keys($headers_lower))) throw new AS2Exception('A malformed AS2 message (no message-id) was received, the message will be suspended.');
                if (!in_array('as2-from', array_keys($headers_lower)))   throw new AS2Exception('An AS2 message was received that did not contain the AS2-From header.');
                if (!in_array('as2-to', array_keys($headers_lower)))     throw new AS2Exception('An AS2 message was received that did not contain the AS2-To header.');
    
                // main save action
                $filename = self::saveMessage($data, $headers);
                
                // request building
                $request = new AS2Request($data, $headers);
                
                // warning / notification
                if (trim($request->getHeader('as2-from')) == trim($request->getHeader('as2-to'))) AS2Log::warning($request->getHeader('message-id'), 'The AS2-To name is identical to the AS2-From name.');
                // log some informations
                AS2Log::info($request->getHeader('message-id'), 'Incoming transmission is a AS2 message, raw message size: ' . round(strlen($data)/1024, 2) . ' KB.');
                
                // try to decrypt data
                $decrypted = $request->decrypt();
                // save data if encrypted
                if ($decrypted) {
                    $content = file_get_contents($decrypted);
                    self::saveMessage($content, array(), $filename.'.decrypted', 'decrypted');
                }
            }
            elseif (!$request instanceof AS2Request){
                throw new AS2Exception('Unexpected error occurs while handling AS2 message : bad format');
            }
            
            $object = $request->getObject();
        }
        catch(Exception $e){
            // get error while handling request
            $error = $e;
            //throw $e;
        }
        
        //
        if ($object instanceof AS2Message || (!is_null($error) && !($object instanceof AS2MDN))){
            $object_type = 'Message';
            AS2Log::info(false, 'Incoming transmission is a Message.');
            
            try {
                $mdn = false;
                
                if (is_null($error)){
                    $object->decode();
                    $files = $object->getFiles();
                    AS2Log::info(false, count($files).' payload(s) found in incoming transmission.');
                    foreach($files as $key => $file){
                        $content = file_get_contents($file['path']);
                        AS2Log::info(false, 'Payload #'.($key+1).' : '.round(strlen($content) / 1024, 2).' KB / "'.$file['filename'].'".');
                        self::saveMessage($content, array(), $filename.'.payload_'.$key, 'payload');
                    }

                    $mdn = $object->generateMDN($error);
                    $mdn->encode($object);
                }
                else {
                    throw $error;
                }
            }
            catch(Exception $e){
                $params = array('partner_from' => $headers_lower['as2-from'],
                                'partner_to'   => $headers_lower['as2-to']);
                $mdn = new AS2MDN($e);
                $mdn->setAttribute('original-message-id', $headers_lower['message-id']);
                $mdn->encode();
            }
            
            if ($mdn){
                if (!$headers_lower['receipt-delivery-option']) {
                    // SYNC method
                    $headers = $mdn->getHeaders();
                    foreach($headers as $key => $value)
                        header($key.': '.$value);
                    echo $mdn->getContent();
                    AS2Log::info(false, 'An AS2 MDN has been sent.');
                }
                else {
                    // ASYNC method

                    // cut connexion and wait a few seconds
                    ob_end_clean();
                    header("Connection: close\r\n");
                    header("Content-Encoding: none\r\n");
                    ignore_user_abort(true); // optional
                    ob_start();
                    $size = ob_get_length();
                    header("Content-Length: $size");
                    ob_end_flush();     // Strange behaviour, will not work
                    flush();            // Unless both are called !
                    ob_end_clean();
                    session_write_close();

                    // wait 5 seconds before sending MDN notification
                    sleep(5);

                    // send mdn
                    $client = new AS2Client();
                    $result = $client->sendRequest($mdn);
                    if ($result['info']['http_code'] == '200'){
                        AS2Log::info(false, 'An AS2 MDN has been sent.');
                    }
                    else{
                        AS2Log::error(false, 'An error occurs while sending MDN message : ' . $result['info']['http_code']);
                    }
                }
            }
        }
        elseif ($object instanceof AS2MDN) {
            $object_type = 'MDN';
            AS2Log::info(false, 'Incoming transmission is a MDN.');
        }
        else {
            AS2Log::error(false, 'Malformed data.');
        }
    
        if ($request instanceof AS2Request) {
            // build arguments
            $params = array('from'   => $headers_lower['as2-from'],
                            'to'     => $headers_lower['as2-to'],
                            'status' => '',
                            'data'   => '');
            if ($error) {
                $params['status'] = AS2Connector::STATUS_ERROR;
                $params['data']   = array('object'  => $object,
                                          'error'   => $error);
            }
            else {
                $params['status'] = AS2Connector::STATUS_OK;
                $params['data']   = array('object'  => $object,
                                          'error'   => null);
            }
        
            // call PartnerTo's connector
            if ($request->getPartnerTo() instanceof AS2Partner) {
                $connector = $request->getPartnerTo()->connector_class;
                call_user_func_array(array($connector, 'onReceived' . $object_type), $params);
            }
        
            // call PartnerFrom's connector
            if ($request->getPartnerFrom() instanceof AS2Partner) {
                $connector = $request->getPartnerFrom()->connector_class;
                call_user_func_array(array($connector, 'onSent' . $object_type), $params);
            }
        }
        
        return $request;
    }

    /**
     * Save the content of the request for futur handle and/or backup
     * 
     * @param content       The content to save (mandatory)
     * @param headers       The headers to save (optional)
     * @param filename      The filename to use if known (optional)
     * @param type          Values : raw | decrypted | payload (mandatory)
     * 
     * @return       String  : The main filename
     */
    protected static function saveMessage($content, $headers, $filename = '', $type = 'raw'){
        umask(000);
        $dir = '../messages/_rawincoming';
        @mkdir($dir, 0777, true);
        
        if (!$filename) {
            list($micro, ) = explode(' ', microtime());
            $micro = str_pad(round($micro * 1000), 3, '0');
            $host = ($_SERVER['REMOTE_ADDR']?$_SERVER['REMOTE_ADDR']:'unknownhost');
            $filename = date('YmdHis') . $micro . '_' . $host . '.as2';
        }

        switch($type){
            case 'raw':
                file_put_contents($dir . '/' . $filename, $content);
                $tmp = '';
                if (is_array($headers)){
                    foreach($headers as $key => $value){
                        $tmp .= $key . ': ' . $value . "\r\n";
                    }
                    file_put_contents($dir . '/' . $filename . '.header', $tmp);
                }
                break;
                
            case 'decrypted':
            case 'payload':
                file_put_contents($dir . '/' . $filename, $content);
                break;
        }

        return $filename;
    }
}