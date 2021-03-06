<?php

namespace TheFox\Imap;

use Exception;
use RuntimeException;
use InvalidArgumentException;

use Zend\Mail\Message as ZendMailMessage;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Filesystem\Filesystem;

use TheFox\Imap\Exception\NotImplementedException;
use TheFox\Imap\Storage\AbstractStorage;
use TheFox\Imap\Storage\DirectoryStorage;
use TheFox\Logger\Logger;
use TheFox\Logger\StreamHandler;
use TheFox\Network\Socket;

class Server extends Thread{
	
	const LOOP_USLEEP = 10000;
	
	private $log;
	private $socket;
	private $isListening = false;
	private $ip;
	private $port;
	private $clientsId = 0;
	private $clients = array();
	private $defaultStoragePath = 'maildata';
	private $defaultStorage;
	private $storages = array();
	private $eventsId = 0;
	private $events = array();
	
	public function __construct($ip = '127.0.0.1', $port = 20143){
		$this->setIp($ip);
		$this->setPort($port);
	}
	
	public function setLog($log){
		$this->log = $log;
	}
	
	public function getLog(){
		return $this->log;
	}
	
	public function setIp($ip){
		$this->ip = $ip;
	}
	
	public function setPort($port){
		$this->port = $port;
	}
	
	public function init(){
		if(!$this->log){
			$this->log = new Logger('server');
			$this->log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
			if(file_exists('log')){
				$this->log->pushHandler(new StreamHandler('log/server.log', Logger::DEBUG));
			}
		}
		// @codeCoverageIgnoreStart
		if(!TEST){
			$this->log->info('start');
			$this->log->info('ip = "'.$this->ip.'"');
			$this->log->info('port = "'.$this->port.'"');
		}
		// @codeCoverageIgnoreEnd
	}
	
	/**
	 * @codeCoverageIgnore
	 */
	public function listen(){
		if($this->ip && $this->port){
			#$this->log->notice('listen on '.$this->ip.':'.$this->port);
			
			$this->socket = new Socket();
			
			$bind = false;
			try{
				$bind = $this->socket->bind($this->ip, $this->port);
			}
			catch(Exception $e){
				$this->log->error($e->getMessage());
			}
			
			if($bind){
				try{
					if($this->socket->listen()){
						$this->log->notice('listen ok');
						$this->isListening = true;
						
						return true;
					}
				}
				catch(Exception $e){
					$this->log->error($e->getMessage());
				}
			}
			
		}
	}
	
	/**
	 * @codeCoverageIgnore
	 */
	public function run(){
		if(!$this->socket){
			throw new RuntimeException('Socket not initialized. You need to execute listen().', 1);
		}
		
		$readHandles = array();
		$writeHandles = null;
		$exceptHandles = null;
		
		if($this->isListening){
			$readHandles[] = $this->socket->getHandle();
		}
		foreach($this->clients as $clientId => $client){
			// Collect client handles.
			$readHandles[] = $client->getSocket()->getHandle();
			
			// Run client.
			$client->run();
		}
		$readHandlesNum = count($readHandles);
		
		$handlesChanged = $this->socket->select($readHandles, $writeHandles, $exceptHandles);
		if($handlesChanged){
			foreach($readHandles as $readableHandle){
				if($this->isListening && $readableHandle == $this->socket->getHandle()){
					// Server
					$socket = $this->socket->accept();
					if($socket){
						$client = $this->clientNew($socket);
						$client->sendHello();
						#$this->log->debug('new client: '.$client->getId().', '.$client->getIpPort());
					}
				}
				else{
					// Client
					$client = $this->clientGetByHandle($readableHandle);
					if($client){
						if(feof($client->getSocket()->getHandle())){
							$this->clientRemove($client);
						}
						else{
							#$this->log->debug('old client: '.$client->getId().', '.$client->getIpPort());
							$client->dataRecv();
							if($client->getStatus('hasShutdown')){
								$this->clientRemove($client);
							}
						}
					}
				}
			}
		}
	}
	
	/**
	 * @codeCoverageIgnore
	 */
	public function loop(){
		while(!$this->getExit()){
			$this->run();
			usleep(static::LOOP_USLEEP);
		}
		
		$this->shutdown();
	}
	
	/**
	 * @codeCoverageIgnore
	 */
	public function shutdown(){
		#$this->log->debug('shutdown');
		
		// Notify all clients.
		foreach($this->clients as $clientId => $client){
			$client->sendBye('Server shutdown');
			$this->clientRemove($client);
		}
		
		// Remove all temp files and save dbs.
		$this->shutdownStorages();
		
		#$this->log->debug('shutdown done');
	}
	
	public function clientNew($socket){
		$this->clientsId++;
		
		$client = new Client();
		$client->setSocket($socket);
		$client->setId($this->clientsId);
		$client->setServer($this);
		$this->clients[$this->clientsId] = $client;
		
		return $client;
	}
	
	public function clientGetByHandle($handle){
		$rv = null;
		
		foreach($this->clients as $clientId => $client){
			if($client->getSocket()->getHandle() == $handle){
				$rv = $client;
				break;
			}
		}
		
		return $rv;
	}
	
	public function clientRemove(Client $client){
		$this->log->debug('client remove: '.$client->getId());
		
		$client->shutdown();
		
		$clientsId = $client->getId();
		unset($this->clients[$clientsId]);
	}
	
	public function getDefaultStorage(){
		if(!$this->defaultStorage){
			$storage = new DirectoryStorage();
			$storage->setPath($this->defaultStoragePath);
			
			$this->addStorage($storage);
		}
		return $this->defaultStorage;
	}
	
	public function addStorage(AbstractStorage $storage){
		#fwrite(STDOUT, 'add: '.$storage->getType().' '.get_class($storage)."\n");
		
		if(!$this->defaultStorage){
			$this->defaultStorage = $storage;
			
			$dbPath = $storage->getPath();
			if(substr($dbPath, -1) == '/'){
				$dbPath = substr($dbPath, 0, -1);
			}
			
			$dbPath .= '.yml';
			$storage->setDbPath($dbPath);
			
			$db = new MsgDb($dbPath);
			$db->load();
			$storage->setDb($db);
		}
		else{
			$this->storages[] = $storage;
		}
		
		#\Doctrine\Common\Util\Debug::dump($this->defaultStorage);
		#\Doctrine\Common\Util\Debug::dump($this->storages);
	}
	
	public function shutdownStorages(){
		$filesystem = new Filesystem();
		
		$this->getDefaultStorage()->save();
		
		foreach($this->storages as $storageId => $storage){
			#fwrite(STDOUT, 'stor: '.$storageId.' '.$storage->getType().' '.get_class($storage)."\n");
			if($storage->getType() == 'temp'){
				$filesystem->remove($storage->getPath());
				
				#fwrite(STDOUT, ' -> db /'.$storage->getDbPath().'/'."\n");
				if($storage->getDbPath()){
					#fwrite(STDOUT, '    -> db '.$storage->getDbPath()."\n");
					$filesystem->remove($storage->getDbPath());
				}
			}
			elseif($storage->getType() == 'normal'){
				$storage->save();
			}
		}
	}
	
	public function addFolder($path){
		$storage = $this->getDefaultStorage();
		$successful = $storage->createFolder($path);
		
		foreach($this->storages as $storageId => $storage){
			$storage->createFolder($path);
		}
		
		return $successful;
	}
	
	public function getFolders($baseFolder, $searchFolder, $recursive = false, $level = 0){
		$func = __FUNCTION__;
		$this->log->debug($func.$level.': /'.$baseFolder.'/ /'.$searchFolder.'/ '.(int)$recursive.', '.$level);
		
		// @codeCoverageIgnoreStart
		if($level >= 100){
			return array();
		}
		// @codeCoverageIgnoreEnd
		
		if($baseFolder == '' && $searchFolder == 'INBOX'){
			return $this->$func('INBOX', '*', true, $level + 1);
		}
		
		$storage = $this->getDefaultStorage();
		$folders = $storage->getFolders($baseFolder, $searchFolder, $recursive);
		$rv = array();
		foreach($folders as $folder){
			$folder = str_replace('/', '.', $folder);
			$rv[] = $folder;
		}
		return $rv;
	}
	
	public function folderExists($folder){
		$storage = $this->getDefaultStorage();
		return $storage->folderExists($folder);
	}
	
	public function getNextMsgId(){
		$storage = $this->getDefaultStorage();
		return $storage->getNextMsgId();
	}
	
	public function getMsgSeqById($msgId){
		#fwrite(STDOUT, 'getMsgIdBySeq: /'.$msgId.'/'.PHP_EOL);
		
		$storage = $this->getDefaultStorage();
		return $storage->getMsgSeqById($msgId);
	}
	
	public function getMsgIdBySeq($seqNum, $folder){
		#fwrite(STDOUT, 'getMsgIdBySeq: '.$seqNum.' /'.$folder.'/'.PHP_EOL);
		
		$storage = $this->getDefaultStorage();
		return $storage->getMsgIdBySeq($seqNum, $folder);
	}
	
	public function getFlagsById($msgId){
		$storage = $this->getDefaultStorage();
		return $storage->getFlagsById($msgId);
	}
	
	public function setFlagsById($msgId, $flags){
		$storage = $this->getDefaultStorage();
		$storage->setFlagsById($msgId, $flags);
	}
	
	public function getFlagsBySeq($seqNum, $folder){
		$storage = $this->getDefaultStorage();
		return $storage->getFlagsBySeq($seqNum, $folder);
	}
	
	public function setFlagsBySeq($seqNum, $folder, $flags){
		$storage = $this->getDefaultStorage();
		$storage->setFlagsBySeq($seqNum, $folder, $flags);
	}
	
	public function getCountMailsByFolder($folder, $flags = null){
		$storage = $this->getDefaultStorage();
		return $storage->getMailsCountByFolder($folder, $flags);
	}
	
	public function addMail(ZendMailMessage $mail, $folder = null, $flags = null, $recent = true){
		$this->eventExecute(Event::TRIGGER_MAIL_ADD_PRE);
		
		$storage = $this->getDefaultStorage();
		$mailStr = $mail->toString();
		
		$msgId = $storage->addMail($mailStr, $folder, $flags, $recent);
		#fwrite(STDOUT, 'addMail msgId: '.$msgId.PHP_EOL);
		
		foreach($this->storages as $storageId => $storage){
			$storage->addMail($mailStr, $folder, $flags, $recent);
		}
		
		$this->eventExecute(Event::TRIGGER_MAIL_ADD, array($mail));
		
		$this->eventExecute(Event::TRIGGER_MAIL_ADD_POST, array($msgId));
		
		return $msgId;
	}
	
	public function removeMailById($msgId){
		$storage = $this->getDefaultStorage();
		$this->log->debug('remove msgId: /'.$msgId.'/');
		$storage->removeMail($msgId);
		
		foreach($this->storages as $storageId => $storage){
			$storage->removeMail($msgId);
		}
	}
	
	public function removeMailBySeq($seqNum, $folder){
		$this->log->debug('remove seq: /'.$seqNum.'/');
		
		$msgId = $this->getMsgIdBySeq($seqNum, $folder);
		if($msgId){
			$this->removeMailById($msgId);
		}
	}
	
	public function copyMailById($msgId, $dstFolder){
		$storage = $this->getDefaultStorage();
		$this->log->debug('copy msgId: /'.$msgId.'/');
		$storage->copyMailById($msgId, $dstFolder);
		
		foreach($this->storages as $storageId => $storage){
			$storage->copyMailById($msgId, $dstFolder);
		}
	}
	
	public function copyMailBySequenceNum($seqNum, $folder, $dstFolder){
		$storage = $this->getDefaultStorage();
		$this->log->debug('copy seq: /'.$seqNum.'/');
		$storage->copyMailBySequenceNum($seqNum, $folder, $dstFolder);
		
		foreach($this->storages as $storageId => $storage){
			$storage->copyMailBySequenceNum($seqNum, $folder, $dstFolder);
		}
	}
	
	public function getMailById($msgId){
		$storage = $this->getDefaultStorage();
		$mailStr = $storage->getPlainMailById($msgId);
		$mail = ZendMailMessage::fromString($mailStr);
		
		return $mail;
	}
	
	public function getMailBySeq($seqNum, $folder){
		$rv = null;
		
		$msgId = $this->getMsgIdBySeq($seqNum, $folder);
		if($msgId){
			$rv = $this->getMailById($msgId);
		}
		
		return $rv;
	}
	
	public function getMailIdsByFlags($flags){
		$storage = $this->getDefaultStorage();
		$msgsIds = $storage->getMsgsByFlags($flags);
		return $msgsIds;
	}
	
	public function eventAdd(Event $event){
		$this->eventsId++;
		$this->events[$this->eventsId] = $event;
	}
	
	private function eventExecute($trigger, $args = array()){
		foreach($this->events as $eventId => $event){
			if($event->getTrigger() == $trigger){
				$event->execute($args);
			}
		}
	}
	
}
