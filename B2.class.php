<?php

class B2Exception extends Exception {}

class B2 {

    const DEFAULT_ENDPOINT = 'https://api.backblazeb2.com/b2api/v2/';

    private $access_key;
    private $secret_key;
    private $redis;

    private $endpoint, $downloadEndpoint;

    private $curl_opts=[],$curl_error;
		private $auth=[],$buckets=[];
		private static $cacheHashes=['auth'=>'B2_AUTH:','buckets'=>'B2_BUCKETS:','uploadURL'=>'B2_UPLOAD:'];
		private $ttl=86300;


		private function stat($result) {

			$info = curl_getinfo($result['handle']);

			if ( empty($info['http_code']) ) {

				$code=$result['result'];
			} else {

				$code=$info['http_code'];
			}

			return [
		
				 'code' => (int)$code
				,'size_download' => (int)$info['size_download']
				,'size_upload' => (int)$info['size_upload']
				,'total_time'=> ceil($info['total_time']*1000000)
				,'ttfb' => ceil($info['starttransfer_time']*1000000)
				,'ns' => ceil($info['namelookup_time']*1000000)
				,'connect_time' => ceil($info['connect_time']*1000000)
				,'endpoint'=>$this->downloadEndpoint
			];

		}

		private function curlOK($ch,$body,$ok=null) {

       if (curl_errno($ch) || curl_error($ch)) {
           $this->curl_error = array(
               'code' => curl_errno($ch),
               'message' => curl_error($ch),
           );
					 error_log('Error: '. __METHOD__. ': '. serialize($this->curl_error));
					 return false;
       } else {
          
					$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
					if ( $code == $ok) return true;

          if ($code > 300) {

						$resp=json_decode($body,true);

						if ( $code == 401 ) {

							$this->forgetAuth();

						} elseif ( $code == 400 || $code == 403 || $code == 405 ) {
					
							#error_log(__METHOD__.': '. serialize($resp));
							if ( ( $code == 400 || $code == 404 ) && ( $resp['code'] == 'already_hidden' || $resp['code'] == 'no_such_file' ) ) return true;

							alert_admin(__METHOD__,serialize($this->curl_error));

						}
						$this->curl_error = array(
								 'code' => $code,
								 'message' => $resp['code'] . ' ' . $resp['message'] . ' ' . $resp['status'],
					 );
						
						error_log('Error: '. __METHOD__. ': '. serialize($this->curl_error));
					 	return false;
		
					}
			 }

			 return true;
		}

		private function forgetAuth() {

			$this->redis->delete($this->cacheHash('auth'));

		}

		private function cacheHash($type) {

			$creds=md5($this->access_key.':'.$this->secret_key);
			return self::$cacheHashes[$type] . $creds;
		}

		private function getAuth() {

			$data=$this->redis->mGet([$this->cacheHash('auth'),$this->cacheHash('buckets')]);

			if ( empty($data[0]) ) {

				$uri=$this->endpoint . 'b2_authorize_account';
				error_log('Notice: '.__METHOD__.' '.$uri);
				$credentials = base64_encode($this->access_key . ":" . $this->secret_key);

				$session = curl_init($uri);
        curl_setopt_array($session, $this->curl_opts);
				
				$headers = array();
				$headers[] = "Accept: application/json";
				$headers[] = "Authorization: Basic " . $credentials;
				curl_setopt($session, CURLOPT_HTTPHEADER, $headers); 

				curl_setopt($session, CURLOPT_HTTPGET, true); 
				curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
				$data[0] = curl_exec($session);

				if ( $this->curlOK($session,$data[0]) ) {
					
					$this->redis->setex($this->cacheHash('auth'),$this->ttl,$data[0]);
				} else {

					$data[0]='{}';
				}
				curl_close($session);
			}

			$this->auth=json_decode($data[0],true);

			if ( empty($data[1]) && !empty($this->auth['apiUrl']) && !empty($this->auth['accountId']) && !empty($this->auth['authorizationToken']) ) {

				$uri=$this->auth['apiUrl'] . "/b2api/v2/b2_list_buckets";
				error_log('Notice: '.__METHOD__.' ' . $uri);
				$session = curl_init($uri);
        curl_setopt_array($session, $this->curl_opts);
				$post = array("accountId" => $this->auth['accountId'],'bucketTypes'=>["allPublic", "allPrivate"]);
				$post_fields = json_encode($post);
				curl_setopt($session, CURLOPT_POSTFIELDS, $post_fields); 
				
				$headers = array();
				$headers[] = "Authorization: " . $this->auth['authorizationToken'];
				curl_setopt($session, CURLOPT_HTTPHEADER, $headers); 

				curl_setopt($session, CURLOPT_POST, true);
				curl_setopt($session, CURLOPT_RETURNTRANSFER, true); 
				$server_output = curl_exec($session); 

				$data[1] = curl_exec($session);

				if ( $this->curlOK($session,$data[1]) ) {
					
					$this->redis->setex($this->cacheHash('buckets'),$this->ttl,$data[1]);
				} else {

					$data[1]='{}';
				}
				curl_close($session);
			}

			$this->buckets=json_decode($data[1],true);

			if ( !empty($this->auth) ) return true;
			return false;

		}

		private function getUploadURLFromBackblaze($bucketName) {

			error_log('Notice: '.__METHOD__.': '.$bucketName);

			$backetId=$this->getBucketId($bucketName);
			if ( !empty($backetId) ) {

				$uri=$this->auth['apiUrl'] . "/b2api/v2/b2_get_upload_url";
				$session = curl_init($uri);
				curl_setopt_array($session, $this->curl_opts);
				$post = array("bucketId" => $backetId);

				$post_fields = json_encode($post);
				curl_setopt($session, CURLOPT_POSTFIELDS, $post_fields); 
				
				$headers = array();
				$headers[] = "Authorization: " . $this->auth['authorizationToken'];
				curl_setopt($session, CURLOPT_HTTPHEADER, $headers); 

				curl_setopt($session, CURLOPT_POST, true); 
				curl_setopt($session, CURLOPT_RETURNTRANSFER, true); 
				$server_output = curl_exec($session); 

				$URL = curl_exec($session);

				if ( !$this->curlOK($session,$URL) ) {

					$URL='{}';
				}

			} else {

				$URL='{}';

			}

			return $URL;
		}

		private function getUploadURL($bucketName) {

			while ( true ) {

				$URL=$this->redis->lpop($this->cacheHash('uploadURL').':'.$bucketName);

				if ( empty($URL) ) {

					$URL=$this->getUploadURLFromBackblaze($bucketName);

				}

				$URLJ=json_decode($URL,true);
				if ( empty($URLJ['expire']) ) $URLJ['expire']=time() + $this->ttl;

				if ( $URLJ['expire'] > time() ) break; 

			}

			return $URLJ;
		}

		private function returnUploadUrl($bucketName,$URLJ) {

			$this->redis->lpush($this->cacheHash('uploadURL').':'.$bucketName,json_encode($URLJ));
			
		}

    public function __construct($access_key, $secret_key, $downloadEndpoint, $redis ,$curl_opts=[CURLOPT_CONNECTTIMEOUT => 30,CURLOPT_LOW_SPEED_LIMIT => 1,CURLOPT_LOW_SPEED_TIME => 30], $endpoint = null) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->curl_opts = $curl_opts;
        $this->endpoint = $endpoint ?: self::DEFAULT_ENDPOINT;
        $this->downloadEndpoint = $downloadEndpoint;
				$this->redis = $redis;

    }

    public function useCurlOpts($curl_opts) {
        $this->curl_opts = $curl_opts;
        return $this;
    }

		private function execMulti($curls) {

      $mh = curl_multi_init();
			$results=[];

			foreach ( $curls as $n => $curl ) {

				curl_multi_add_handle($mh, $curl);
			}
				$result=[];
				$running = null;
						
				do {
						curl_multi_exec($mh, $running);
						if ($running) curl_multi_select($mh);

				} while ($running > 0);

				foreach ( $curls as $n => $curl ) {

					$result = curl_multi_info_read($mh);
					$results[]=[
						 'curl' => $curl
						,'body' => curl_multi_getcontent($curl)
						,'stats'=> $this->stat($result)
					];
					curl_multi_remove_handle($mh, $result['handle']);
				}

				curl_multi_close($mh);
				return $results;

		}

		private function getBucketId($bucketName) {

			foreach ( $this->buckets['buckets'] as $k => $bucket ) {

				if ( $bucket['bucketName'] == $bucketName ) return $bucket['bucketId'];
			}

			return false;

		}

    public function getObjects($bucketName, $path,$version=0) {

			$out=[];
			$ok=0;

			try {

				foreach ( $path as $k => $p ) {

					if ( $version > 0 ) {

						$addURL='?'.$version;
					} else {

						$addURL='';

					}
					$uri=$this->downloadEndpoint .'/'.$bucketName. '/' . $p  . $addURL;
					$sessions[$k] = curl_init($uri);
					curl_setopt_array($sessions[$k], $this->curl_opts);
					curl_setopt($sessions[$k], CURLOPT_RETURNTRANSFER, true); 

				}

				$results=$this->execMulti($sessions);

				foreach ( $results as $k => $result ) {

					if ( $this->curlOK($result['curl'],$result['body'],404) ) {

						if ( curl_getinfo($result['curl'], CURLINFO_RESPONSE_CODE) == 404 ) {

							$res=null;
						} else {

							$res=true;
						}
					} else {

						$res=false;

					}
					$out[]=['result'=>$res,'stats'=>$result['stats'],'body'=>$result['body']];
				}

			} catch ( \B2Exception $e ) {

				error_log('Error: '. __METHOD__. ': '. $e->getMessage());

			}

			return $out;
    }

    public function deleteObjects($bucketName, $path) {

			$out=[];

			try {

				if ( !$this->getAuth() ) throw new B2Exception('No auth info');
				$backetId=$this->getBucketId($bucketName);

				if ( empty($backetId) ) throw new B2Exception('No bucket id');
				$headers = ["Authorization: " . $this->auth['authorizationToken']];
				$uri=$this->auth['apiUrl'] . "/b2api/v2/b2_hide_file";

				foreach ( $path as $k => $p ) {

					$data = ["bucketId" => $backetId, "fileName" => $p];
					$post_fields = json_encode($data);
					
					$sessions[$k] = curl_init($uri);
					curl_setopt_array($sessions[$k], $this->curl_opts);
					curl_setopt($sessions[$k], CURLOPT_POST, true); 
					curl_setopt($sessions[$k], CURLOPT_POSTFIELDS, $post_fields); 
					curl_setopt($sessions[$k], CURLOPT_RETURNTRANSFER, true); 
					curl_setopt($sessions[$k], CURLOPT_HTTPHEADER, $headers); 

				}

				$results=$this->execMulti($sessions);

				foreach ( $results as $k => $result ) {

					$out[]=['result'=>$this->curlOK($result['curl'],$result['body']),'stats'=>$result['stats']];
				}

			} catch ( \B2Exception $e ) {

				error_log('Error: '. __METHOD__. ': '. $e->getMessage());

			}

			return $out;
    }

    public function putObjects($bucket, $path, $files) {

			$out=[];
			$ok=0;

				try {

					if ( !$this->getAuth() ) throw new B2Exception('No auth info');

					foreach ( $path as $k => $p ) {

						$URL[$k]=$this->getUploadURL($bucket);

						if ( empty($URL[$k]) ) throw new B2Exception('No upload URL bucket info avalible');

						$upload_url = $URL[$k]['uploadUrl'];
						$upload_auth_token = $URL[$k]['authorizationToken'];
						$bucket_id = $URL[$k]['bucketId'];

						$sha1_of_file_data = sha1($files[$k]);
						$sessions[$k] = curl_init($upload_url);
        		curl_setopt_array($sessions[$k], $this->curl_opts);
						curl_setopt($sessions[$k], CURLOPT_POST, true); 
						curl_setopt($sessions[$k], CURLOPT_POSTFIELDS, $files[$k]); 

						$headers = array();
						$headers[] = "Authorization: " . $upload_auth_token;
						$headers[] = "X-Bz-File-Name: " . $p;
						$headers[] = "X-Bz-Content-Sha1: " . $sha1_of_file_data;
						$headers[] = "Content-Type: b2/x-auto";
						curl_setopt($sessions[$k], CURLOPT_HTTPHEADER, $headers); 
						curl_setopt($sessions[$k], CURLOPT_RETURNTRANSFER, true); 
					}

					$results=$this->execMulti($sessions);

					foreach ( $results as $k => $result ) {
						
						$cOK=$this->curlOK($result['curl'],$result['body'])
						$out[]=['result'=>$cOK,'stats'=>$result['stats']];

						if ( $cOK ) {

							$this->returnUploadUrl($bucket,$URL[$k]);
						}
					}

			} catch ( \B2Exception $e ) {

				error_log('Error: '. __METHOD__. ': '. $e->getMessage());

			}
			return $out;
    }
}
