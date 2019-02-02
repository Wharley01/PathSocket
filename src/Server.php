<?php
/**
 * @Author by Sulaiman Adewale.
 * @Date 1/13/2019
 * @Time 11:33 AM
 * @Project Path
 */

namespace Path\Plugins\PathSocket;


class Server
{
    protected $host = "127.0.0.1";
    protected $port = 3330;
    private $socketServer;
    public  $clients = [];
    protected $maxBufferSize = 2048;

    private $error_code_values = [
        102 => "ENETRESET    -- Network dropped connection because of reset",
        103 => "ECONNABORTED -- Software caused connection abort",
        104 => "ECONNRESET   -- Connection reset by peer",
        108 => "ESHUTDOWN    -- Cannot send after transport endpoint shutdown -- probably more of an error on our part, if we're trying to write after the socket is closed.  Probably not a critical error, though.",
        110 => "ETIMEDOUT    -- Connection timed out",
        111 => "ECONNREFUSED -- Connection refused -- We shouldn't see this one, since we're listening... Still not a critical error.",
        112 => "EHOSTDOWN    -- Host is down -- Again, we shouldn't see this, and again, not critical because it's just one connection and we still want to listen to/for others.",
        113 => "EHOSTUNREACH -- No route to host",
        121 => "EREMOTEIO    -- Rempte I/O error -- Their hard drive just blew up.",
        125 => "ECANCELED    -- Operation canceled"
                            ];

    /**
     * Server constructor.
     */
    public function __construct()
    {
//     create socket socket
        $socket = $this->newSocketServer();

        $this->socketServer = $socket;
        //add incoming connection to list of sockets to watch for
        $this->addNewClient($this->socketServer,"server");
        self::log("Server started on socket #{$this->socketServer}");
        $this->listenToSockets();
    }

    private function newSocketServer(){
        $socket = socket_create(
            AF_INET,
            SOCK_STREAM,
            SOL_TCP
        );


        socket_set_option(
            $socket,
            SOL_SOCKET,
            SO_REUSEADDR,
            1
        );
        self::log("Necessary Option Set");


        socket_bind(
            $socket,
            $this->host,
            $this->port
        );
        self::log("Server bond with Websocket Host and port");

        socket_listen($socket,20);

        self::log("Listening to: {$this->host} on Port: {$this->port}");

        return $socket;
    }




    private function getClientId($socket){
        return intval($socket);
    }

    private function addNewClient($client, $type = null){
        $socket_id = $this->getClientId($client);
        if(!isset($this->clients[$socket_id])){
            $this->clients[$socket_id]['socket'] = $client;
            $this->clients[$socket_id]['type'] = $type ?? "client";
            $this->clients[$socket_id]['has_handshake'] = false;
            $this->clients[$socket_id]['is_closed'] = false;
        }
    }

    /**
     * @return array
     */
    public function getClients(): array
    {
        $clients = [];
        foreach($this->clients as $client_id => $client){
            $clients[$client_id] = $client['socket'];
        }
        return $clients;
    }
    function getClientDetails($client){
        $client_id = $this->getClientId($client);
        return $this->clients[$client_id];
    }
    private function removeSocket($socket){
        $socket_id = $this->getClientId($socket);
        unset($this->clients[$socket_id]);
    }
    private function respond($socket,$message){
        socket_write($socket, $message, strlen($message));
    }
    private function sendMsg($msg,$client){

    }

    private function getHeaders($buffer):array {
        $headers = array();
        $lines = explode("\n",$buffer);
        foreach ($lines as $line) {
            if (strpos($line,":") !== false) {
                $header = explode(":",$line,2);
                $headers[strtolower(trim($header[0]))] = trim($header[1]);
            }
            elseif (stripos($line,"get ") !== false) {
                preg_match("/GET (.*) HTTP/i", $buffer, $reqResource);
                $headers['get'] = trim($reqResource[1]);
            }
        }
        return $headers;
    }

    public function listenToSockets(){
        do{
//            self::log("Starting All over");
            $write = null;
            $except = null;
            $tv_sec = 5;
            $all_clients = $this->getClients();
            $total_clients = count($all_clients);
            if (socket_select($all_clients, $write, $except, $tv_sec) < 1) {
//                self::log("No incoming connection, total clients: $total_clients");
            }
            foreach ($all_clients as $client_id => $client){
//                because server socket already listened to, we just need to accept the connection
                if($client == $this->socketServer){
                    $_client = socket_accept($client);
                    if($_client < 0){
                        self::log("unable to accept connection, client: {$client}");
                        continue;//on to the next one
                    }else{
                        self::log("Accepted connection {$client}, which is server");
                        $this->addNewClient($_client);
                    }
                }else{
//receive the connection
                    /*
                     * &$buffer holds the data buffer from client
                     *
                     * */
                    $response_num = @socket_recv($client, $buffer, $this->maxBufferSize, 0);
//
                    if($this->validateClientRequestCode($client,$response_num)){
//                        validate header
//                        check if buffer is done sending
                        if (!$this->clients[$client_id]['has_handshake']) {
                            $tmp = str_replace("\r", '', $buffer);
                            if (strpos($tmp, "\n\n") === false ) {
                                continue; // If the client has not finished sending the header, then wait before sending our upgrade response.
                            }
                        if($this->validateRequestHeaders($client,$this->getHeaders($buffer))){
                            $this->hasHandShake($client);
                        }

                            }else{
//                            check for input instead
                            $this->split_packet($response_num,$buffer,$this->clients[$client_id]);
//                            self::log("has already done handshake for client {$client}");
                        }

                    }

//                    sleep(1);

                }

            }
        }while(true);
    }
    private function calcoffset($headers) {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        } elseif ($headers['length'] > 125) {
            $offset += 2;
        }
        return $offset;
    }
    protected function extractHeaders($message) {
        $header = array(
            'fin'     => $message[0] & chr(128),
            'rsv1'    => $message[0] & chr(64),
            'rsv2'    => $message[0] & chr(32),
            'rsv3'    => $message[0] & chr(16),
            'opcode'  => ord($message[0]) & 15,
            'hasmask' => $message[1] & chr(128),
            'length'  => 0,
            'mask'    => ""
        );

        $header['length'] = (ord($message[1]) >= 128) ? ord($message[1]) - 128 : ord($message[1]);

        if ($header['length'] == 126) {
            if ($header['hasmask']) {
                $header['mask'] = $message[4] . $message[5] . $message[6] . $message[7];
            }
            $header['length'] = ord($message[2]) * 256
                + ord($message[3]);
        }
        elseif ($header['length'] == 127) {
            if ($header['hasmask']) {
                $header['mask'] = $message[10] . $message[11] . $message[12] . $message[13];
            }
            $header['length'] = ord($message[2]) * 65536 * 65536 * 65536 * 256
                + ord($message[3]) * 65536 * 65536 * 65536
                + ord($message[4]) * 65536 * 65536 * 256
                + ord($message[5]) * 65536 * 65536
                + ord($message[6]) * 65536 * 256
                + ord($message[7]) * 65536
                + ord($message[8]) * 256
                + ord($message[9]);
        }
        elseif ($header['hasmask']) {
            $header['mask'] = $message[2] . $message[3] . $message[4] . $message[5];
        }
        //echo $this->strtohex($message);
        //$this->printHeaders($header);
        return $header;
    }
    protected function split_packet($length, $packet, $client) {
        //add PartialPacket and calculate the new $length
        $_client = $client['socket'];
        $fullpacket=$packet;
        $frame_pos=0;
        $frame_id=1;
        while($frame_pos<$length) {
            $headers = $this->extractHeaders($packet);
            $headers_size = $this->calcoffset($headers);
            $framesize=$headers['length']+$headers_size;

            //split frame from packet and process it
            $frame=substr($fullpacket,$frame_pos,$framesize);
            if (($message = $this->deframe($frame, $client,$headers)) !== FALSE) {
                if ($client['is_closed']) {
                    $this->disconnectClient($client['socket']);
                } else {
                    if ((preg_match('//u', $message)) || ($headers['opcode']==2)) {
//                        Data received
//                        execute anything here


                        self::log("MESSAGE Received: ".$message);
                    } else {
                        self::log("not UTF-8\n");
                    }
                }
            }
            //get the new position also modify packet data
            $frame_pos+=$framesize;
            $packet=substr($fullpacket,$frame_pos);
            $frame_id++;
        }
    }
    private function deframe($message, &$client) {
        //echo $this->strtohex($message);
        $headers = $this->extractHeaders($message);
        $pongReply = false;
        $willClose = false;
        switch($headers['opcode']) {
            case 0:
            case 1:
            case 2:
                break;
            case 8:
                // todo: close the connection
                $client['is_closed'] = true;
                return "";
            case 9:
                $pongReply = true;
            case 10:
                break;
            default:
                //$this->disconnect($user); // todo: fail connection
                $willClose = true;
                break;
        }
        /* Deal by split_packet() as now deframe() do only one frame at a time.
        if ($user->handlingPartialPacket) {
          $message = $user->partialBuffer . $message;
          $user->handlingPartialPacket = false;
          return $this->deframe($message, $user);
        }
        */

        if ($this->checkRSVBits($headers,$client)) {
            return false;
        }
        if ($willClose) {
            // todo: fail the connection
            return false;
        }
        $payload = $this->extractPayload($message,$headers);
        if ($pongReply) {
            $reply = $this->frame($payload,$client,'pong');
            socket_write($client['socket'],$reply,strlen($reply));
            return false;
        }
        if ($headers['length'] > strlen($this->applyMask($headers,$payload))) {
            $client['partial_buffer'] = $message;
            return false;
        }
        $payload = $this->applyMask($headers,$payload);
        if ($headers['fin']) {
            $client['partialMessage'] = "";
            return $payload;
        }
        $client['partialMessage'] = $payload;
        return false;
    }

    protected function frame($message, $user, $messageType='text', $messageContinues=false) {
        switch ($messageType) {
            case 'continuous':
                $b1 = 0;
                break;
            case 'text':
                $b1 = ($user->sendingContinuous) ? 0 : 1;
                break;
            case 'binary':
                $b1 = ($user->sendingContinuous) ? 0 : 2;
                break;
            case 'close':
                $b1 = 8;
                break;
            case 'ping':
                $b1 = 9;
                break;
            case 'pong':
                $b1 = 10;
                break;
        }
        if ($messageContinues) {
            $user->sendingContinuous = true;
        }
        else {
            $b1 += 128;
            $user->sendingContinuous = false;
        }
        $length = strlen($message);
        $lengthField = "";
        if ($length < 126) {
            $b2 = $length;
        }
        elseif ($length < 65536) {
            $b2 = 126;
            $hexLength = dechex($length);
            //$this->stdout("Hex Length: $hexLength");
            if (strlen($hexLength)%2 == 1) {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;
            for ($i = $n; $i >= 0; $i=$i-2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 2) {
                $lengthField = chr(0) . $lengthField;
            }
        }
        else {
            $b2 = 127;
            $hexLength = dechex($length);
            if (strlen($hexLength)%2 == 1) {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;
            for ($i = $n; $i >= 0; $i=$i-2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 8) {
                $lengthField = chr(0) . $lengthField;
            }
        }
        return chr($b1) . chr($b2) . $lengthField . $message;
    }

    protected function extractPayload($message,$headers) {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        }
        elseif ($headers['length'] > 125) {
            $offset += 2;
        }
        return substr($message,$offset);
    }
    protected function checkRSVBits($headers,$user) { // override this method if you are using an extension where the RSV bits are used.
        if (ord($headers['rsv1']) + ord($headers['rsv2']) + ord($headers['rsv3']) > 0) {
            //$this->disconnect($user); // todo: fail connection
            return true;
        }
        return false;
    }
    protected function applyMask($headers,$payload) {
        $effectiveMask = "";
        if ($headers['hasmask']) {
            $mask = $headers['mask'];
        }
        else {
            return $payload;
        }
        while (strlen($effectiveMask) < strlen($payload)) {
            $effectiveMask .= $mask;
        }
        while (strlen($effectiveMask) > strlen($payload)) {
            $effectiveMask = substr($effectiveMask,0,-1);
        }
        return $effectiveMask ^ $payload;
    }
    private function validateClientRequestCode($client,$request){
        $request_code = socket_last_error($client);
        if($request === false){
            if(isset($this->error_code_values[$request_code])){
                self::log($this->error_code_values[$request_code]." Requesting: $request");
                $this->disconnectClient($client);
                return false;
            }elseif($request_code == 0){
                self::log("connection lost for client {$client}");
                self::log("Error: ".socket_strerror($request_code));
            }
        }else{
            return true;
        }
        return true;
    }
    private function disconnectClient($client){
        $client_id = $this->getClientId($client);
        unset($this->clients[$client_id]);
        self::log("Disconnected Client: {$client}");
        socket_close($client);
    }

    public function writeResponse($client,$response){
        $response .= "\n\r";
        if($rem = socket_write($client,$response,strlen($response)) === false){
            self::log("WRITE ERROR: ". socket_strerror(socket_last_error($client)) );
        }
    }

    private function validateRequestHeaders($client,$headers){
        $response = null;

        if(!isset($headers['host'])){
            $response = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['upgrade']) || strtolower($headers['upgrade']) != 'websocket'){
            $response = "HTTP/1.1 400 Bad Request -- 'upgrade missing'";
        }
        if(!isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === FALSE){
            $response = "HTTP/1.1 400 Bad Request -- 'Connection'";
            self::log($headers['connection']);
        }
        if(!isset($headers['sec-websocket-key'])){
            $response = "HTTP/1.1 400 Bad Request -- 'socket-key'";
        }
        if(!isset($headers['sec-websocket-version']) || strtolower($headers['sec-websocket-version']) != 13){
            $response = "HTTP/1.1 426 Upgrade Required\r\nSec-WebSocketVersion: 13 -- 'socket-version'";
        }
        if(is_null($response)){
            $response_headers = "HTTP/1.1 101 Switching Protocols\r\n";
            $response_headers .= "Upgrade: websocket\r\n";
            $response_headers .= "Connection: Upgrade\r\n";
            $response_headers .= "Sec-WebSocket-Accept: ".self::generateToken($headers['sec-websocket-key'])."\r\n";
            echo PHP_EOL.PHP_EOL;
            $this->writeResponse($client,$response_headers);
            return true;
        }else{
            $this->writeResponse($client,$response);
            return false;
        }
    }

    static public function generateToken($client_key){
        $magic_key = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
        $webSocketKeyHash = sha1($client_key . $magic_key);
        $rawToken = "";
        for ($i = 0; $i < 20; $i++) {
            $rawToken .= chr(hexdec(substr($webSocketKeyHash,$i*2, 2)));
        }
        $handshakeToken = base64_encode($rawToken);
        return $handshakeToken;
    }

    private function doHandShake($client,$headers){

    }

    private function clientIsActive($client):bool{
        return array_key_exists($this->getClientId($client),$this->clients);
    }

    private function hasHandShake($client){
        $client_id = $this->getClientId($client);
        $this->clients[$client_id]['has_handshake'] = true;
    }

    private function logError($socket,$code = 0){
        echo "error #" . $code . ": " . socket_strerror(socket_last_error($socket)) . "\n";
        exit();
    }

    public function watchClientMsg($socket,$client){
        $message = socket_read($client, 2048, PHP_NORMAL_READ);
        if($message === false){
            $this->logError($socket,1);
            socket_close($socket);
            $this->removeSocket($socket);
        }else{
            echo PHP_EOL.$message;
        }
    }

    /**
     * @param $text
     */
    private static function log($text){
        echo PHP_EOL.$text;
    }


}