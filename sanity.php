<?php
/* 
The MIT License (MIT)
Copyright (c) 2018 AroDev 

www.arionum.com

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,
DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE
OR OTHER DEALINGS IN THE SOFTWARE.
*/
set_time_limit(0);
error_reporting(0);

// make sure it's not accessible in the browser
if(php_sapi_name() !== 'cli') die("This should only be run as cli");

// make sure there's only a single sanity process running at the same time
if(file_exists("tmp/sanity-lock")){

	$pid_time=filemtime("tmp/sanity-lock");
	// if the process died, restart after 1day
	if(time()-$pid_time>86400){
		@unlink("tmp/sanity-lock");
	}
	die("Sanity lock in place");
} 
// set the new sanity lock
$lock = fopen("tmp/sanity-lock", "w");
fclose($lock);
$arg=trim($argv[1]);
$arg2=trim($argv[2]);

// sleep for 10 seconds to make sure there's a delay between starting the sanity and other processes
if($arg!="microsanity") sleep(10);


require_once("include/init.inc.php");

// the sanity can't run without the schema being installed
if($_config['dbversion']<2){
	die("DB schema not created");
	@unlink("tmp/sanity-lock");
	exit;
}

$block=new Block();
$acc=new Account();
$current=$block->current();

// the microsanity process is an anti-fork measure that will determine the best blockchain to choose for the last block
$microsanity=false;
if($arg=="microsanity"&&!empty($arg2)){

do {
	// the microsanity runs only against 1 specific peer
	$x=$db->row("SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP() AND ip=:ip",array(":ip"=>$arg2));
	
	if(!$x){ echo "Invalid node - $arg2\n"; break; }
	$url=$x['hostname']."/peer.php?q=";
	$data=peer_post($url."getBlock",array("height"=>$current['height']));
	
	if(!$data) {echo "Invalid getBlock result\n"; break; }
	// nothing to be done, same blockchain
	if($data['id']==$current['id']) {echo "Same block\n"; break;}

	// the blockchain with the most transactions wins the fork (to encourage the miners to include as many transactions as possible) / might backfire on garbage
	if($current['transactions']>$data['transactions']){
		echo "Block has less transactions\n";
		break;
	} elseif($current['transactions']==$data['transactions']) {
		// transform the first 12 chars into an integer and choose the blockchain with the biggest value
		$no1=hexdec(substr(coin2hex($current['id']),0,12));
		$no2=hexdec(substr(coin2hex($data['id']),0,12));
		
		if(gmp_cmp($no1,$no2)!=-1){
			echo "Block hex larger than current\n";
			break;
		}
	}
	// make sure the block is valid
	$prev = $block->get($current['height']-1);
	$public=$acc->public_key($data['generator']);
	if(!$block->mine($public, $data['nonce'],$data['argon'],$block->difficulty($current['height']-1),$prev['id'], $prev['height'])) { echo "Invalid prev-block\n"; break;}
	if(!$block->check($data)) break;

	// delete the last block
	$block->pop(1);


	// add the new block	
	echo "Starting to sync last block from $x[hostname]\n";
	$b=$data;
	$res=$block->add($b['height'], $b['public_key'], $b['nonce'], $b['data'], $b['date'], $b['signature'], $b['difficulty'], $b['reward_signature'], $b['argon']);	
	if(!$res) {
		
		_log("Block add: could not add block - $b[id] - $b[height]"); 					
		
		break; 
	}
	
	_log("Synced block from $host - $b[height] $b[difficulty]"); 
	



} while(0);

	@unlink("tmp/sanity-lock");
exit;
} 


$t=time();
//if($t-$_config['sanity_last']<300) {@unlink("tmp/sanity-lock");  die("The sanity cron was already run recently"); }

// update the last time sanity ran, to set the execution of the next run
$db->run("UPDATE config SET val=:time WHERE cfg='sanity_last'",array(":time"=>$t));
$block_peers=array();
$longest_size=0;
$longest=0;
$blocks=array();
$blocks_count=array();
$most_common="";
$most_common_size=0;
$total_active_peers=0;

// checking peers

// delete the dead peers
$db->run("DELETE from peers WHERE fails>100");

$r=$db->run("SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP()");

$total_peers=count($r);

$peered=array();
// if we have no peers, get the seed list from the official site
if($total_peers==0&&$_config['testnet']==false){
	$i=0;
	echo "No peers found. Attempting to get peers from arionum.com\n";
	$f=file("https://www.arionum.com/peers.txt");
	shuffle($f);
	// we can't connect to arionum.com
	if(count($f)<2){ @unlink("tmp/sanity-lock"); die("Could nto connect to arionum.com! Will try later!\n"); }
	foreach($f as $peer){
		//peer with all until max_peers, this will ask them to send a peering request to our peer.php where we add their peer to the db.
		$peer=trim($peer);
		$peer = filter_var($peer, FILTER_SANITIZE_URL);
        	if (!filter_var($peer, FILTER_VALIDATE_URL)) continue;
		// store the hostname as md5 hash, for easier checking
		$pid=md5($peer);
		// do not peer if we are already peered
		if($peered[$pid]==1) continue;
		$peered[$pid]=1;
		$res=peer_post($peer."/peer.php?q=peer",array("hostname"=>$_config['hostname'], "repeer"=>1));
		if($res!==false) {$i++; echo "Peering OK - $peer\n"; }
		else echo "Peering FAIL - $peer\n";
		if($i>$_config['max_peers']) break;
	}
	// count the total peers we have
	$r=$db->run("SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP()");
	$total_peers=count($r);
	if($total_peers==0){
		// something went wrong, could nto add any peers -> exit
		@unlink("tmp/sanity-lock");
		die("Could not peer to any peers! Please check internet connectivity!\n");
	}
}

// contact all the active peers
foreach($r as $x){
	$url=$x['hostname']."/peer.php?q=";
	// get their peers list
	$data=peer_post($url."getPeers");
	if($data===false) { 
		// if the peer is unresponsive, mark it as failed and blacklist it for a while
		$db->run("UPDATE peers SET fails=fails+1, blacklisted=UNIX_TIMESTAMP()+((fails+1)*60) WHERE id=:id",array(":id"=>$x['id']));		
		continue;
	}
	
		$i=0;
		foreach($data as $peer){
			// store the hostname as md5 hash, for easier checking
			$pid=md5($peer['hostname']);
			// do not peer if we are already peered
        	        if($peered[$pid]==1) continue;
	                $peered[$pid]=1;
			// if it's our hostname, ignore
			if($peer['hostname']==$_config['hostname']) continue;
			// if invalid hostname, ignore
			if (!filter_var($peer['hostname'], FILTER_VALIDATE_URL)) continue;
				// make sure there's no peer in db with this ip or hostname
				if(!$db->single("SELECT COUNT(1) FROM peers WHERE ip=:ip or hostname=:hostname",array(":ip"=>$peer['ip'],":hostname"=>$peer['hostname']))){
					$i++;
					// check a max_test_peers number of peers from each peer
					if($i>$_config['max_test_peers']) break;
					$peer['hostname'] = filter_var($peer['hostname'], FILTER_SANITIZE_URL);
					// peer with each one
					$test=peer_post($peer['hostname']."/peer.php?q=peer",array("hostname"=>$_config['hostname']),20);
					if($test!==false){
						 $total_peers++;
						echo "Peered with: $peer[hostname]\n";
					}
				}
			}
	


	

	// get the current block and check it's blockchain
	$data=peer_post($url."currentBlock");
	if($data===false) continue;
	// peer was responsive, mark it as good
	$db->run("UPDATE peers SET fails=0 WHERE id=:id",array(":id"=>$x['id']));		
		
		$total_active_peers++;
		// add the hostname and block relationship to an array
		$block_peers[$data['id']][]=$x['hostname'];
		// count the number of peers with this block id
		$blocks_count[$data['id']]++;
		// keep block data for this block id
		$blocks[$data['id']]=$data;
		// set the most common block on all peers
		if($blocks_count[$data['id']]>$most_common_size){
			$most_common=$data['id'];
			$most_common_size=$blocks_count[$data['id']];
		}
		// set the largest height block
		if($data['height']>$largest_height){
			 $largest_height=$data['height'];
			 $largest_height_block=$data['id'];
		} elseif($data['height']==$largest_height&&$data['id']!=$largest_height_block){
			// if there are multiple blocks on the largest height, choose one with the smallest (hardest) difficulty
			if($data['difficulty']==$blocks[$largest_height_block]['difficulty']){
					// if they have the same difficulty, choose if it's most common
					if($most_common==$data['id']){
						$largest_height=$data['height'];
						$largest_height_block=$data['id'];
					} else {
						// if this block has more transactions, declare it as winner
						if($blocks[$largest_height_block]['transactions']<$data['transactions']){
							$largest_height=$data['height'];
							$largest_height_block=$data['id'];
						} elseif($blocks[$largest_height_block]['transactions']==$data['transactions']) {
							// if the blocks have the same number of transactions, choose the one with the highest derived integer from the first 12 hex characters
							$no1=hexdec(substr(coin2hex($largest_height_block),0,12));
							$no2=hexdec(substr(coin2hex($data['id']),0,12));
							if(gmp_cmp($no1,$no2)==1){
								$largest_height=$data['height'];
								$largest_height_block=$data['id'];
							}
						}
					}
			} elseif($data['difficulty']<$blocks[$largest_height_block]['difficulty']){
				// choose smallest (hardest) difficulty
				$largest_height=$data['height'];
				$largest_height_block=$data['id'];
			}
			
		}



}
echo "Most common: $most_common\n";
echo "Most common block: $most_common_size\n";
echo "Max height: $largest_height\n";
echo "Current block: $current[height]\n";

// if we're not on the largest height
if($current['height']<$largest_height&&$largest_height>1){
	// start  sanity sync / block all other transactions/blocks
	$db->run("UPDATE config SET val=1 WHERE cfg='sanity_sync'");
	sleep(10);
	_log("Longest chain rule triggered - $largest_height - $largest_height_block");
	// choose the peers which have the larget height block 
	$peers=$block_peers[$largest_height_block];
	shuffle($peers);
	// sync from them
	foreach($peers as $host){
		_log("Starting to sync from $host"); 
		$url=$host."/peer.php?q=";
		$data=peer_post($url."getBlock",array("height"=>$current['height']));
		// invalid data
		if($data===false){ _log("Could not get block from $host - $current[height]");  continue; }
		// if we're not on the same blockchain but the blockchain is most common with over 90% of the peers, delete the last 3 blocks and retry
		if($data['id']!=$current['id']&&$data['id']==$most_common&&($most_common_size/$total_active_peers)>0.90){
			$block->delete($current['height']-3);
			$current=$block->current();
			$data=peer_post($url."getBlock",array("height"=>$current['height']));
			
			if($data===false){_log("Could not get block from $host - $current[height]"); 	 break; }
		
		}elseif($data['id']!=$current['id']&&$data['id']!=$most_common){
			//if we're not on the same blockchain and also it's not the most common, verify all the blocks on on this blockchain starting at current-10 until current
				$invalid=false;
				$last_good=$current['height'];
				for($i=$current['height']-10;$i<$current['height'];$i++){
				 	$data=peer_post($url."getBlock",array("height"=>$i));
					if($data===false){ $invalid=true; break; }
					$ext=$block->get($i);
					if($i==$current['height']-10&&$ext['id']!=$data['id']){ $invalid=true; break; }
					
					if($ext['id']==$data['id']) $last_good=$i;
										
				}
				// if last 10 blocks are good, verify all the blocks
				if($invalid==false) {
					$cblock=array();
					for($i=$last_good;$i<=$largest_height;$i++){
						$data=peer_post($url."getBlock",array("height"=>$i));
						if($data===false){ $invalid=true; break; }
						$cblock[$i]=$data;
					}
					// check if the block mining data is correct
					for($i=$last_good+1;$i<=$largest_height;$i++){
						if(!$block->mine($cblock[$i]['public_key'], $cblock[$i]['nonce'], $cblock[$i]['argon'], $cblock[$i]['difficulty'], $cblock[$i-1]['id'],$cblock[$i-1]['height'])) {$invalid=true; break; }
					}
				}
				// if the blockchain proves ok, delete until the last block
				if($invalid==false){
					$block->delete($last_good);
					$current=$block->current();
					$data=$current;
				}
			
		}
		// if current still doesn't match the data, something went wrong
		if($data['id']!=$current['id']) continue;
		// start syncing all blocks
		while($current['height']<$largest_height){
			$data=peer_post($url."getBlocks",array("height"=>$current['height']+1));
			
			if($data===false){_log("Could not get blocks from $host - height: $current[height]");  break; }
			$good_peer=true;
			foreach($data as $b){
				if(!$block->check($b)){
					_log("Block check: could not add block - $b[id] - $b[height]");
					$good_peer=false; 
					break;
				}
				$res=$block->add($b['height'], $b['public_key'], $b['nonce'], $b['data'], $b['date'], $b['signature'], $b['difficulty'], $b['reward_signature'], $b['argon']);	
				if(!$res) {
					
					_log("Block add: could not add block - $b[id] - $b[height]"); 					
					$good_peer=false;
					break; 
				}
				
				_log("Synced block from $host - $b[height] $b[difficulty]"); 
				
			}	
			if(!$good_peer) break;
			$current=$block->current();		

		}
		if($good_peer) break;
		
	}
	$db->run("UPDATE config SET val=0 WHERE cfg='sanity_sync'",array(":time"=>$t));
}



//rebroadcasting transactions
$forgotten=$current['height']-$_config['sanity_rebroadcast_height'];
$r=$db->run("SELECT id FROM mempool WHERE height<:forgotten ORDER by val DESC LIMIT 10",array(":forgotten"=>$forgotten));
foreach($r as $x){
	$x['id']=san($x['id']);
	system("php propagate.php transaction $x[id]  > /dev/null 2>&1  &");
	$db->run("UPDATE mempool SET height=:current WHERE id=:id",array(":id"=>$x['id'], ":current"=>$current['height']));
}


//add new peers if there aren't enough active
if($total_peers<$_config['max_peers']*0.7){
	$res=$_config['max_peers']-$total_peers;
	$db->run("UPDATE peers SET reserve=0 WHERE reserve=1 AND blacklisted<UNIX_TIMESTAMP() LIMIT $res");
}

//random peer check
$r=$db->run("SELECT * FROM peers WHERE blacklisted<UNIX_TIMESTAMP() and reserve=1 LIMIT ".$_config['max_test_peers']);
foreach($r as $x){
	$url=$x['hostname']."/peer.php?q=";
	$data=peer_post($url."ping");
	if($data===false) $db->run("UPDATE peers SET fails=fails+1, blacklisted=UNIX_TIMESTAMP()+((fails+1)*60) WHERE id=:id",array(":id"=>$x['id']));		
	else $db->run("UPDATE peers SET fails=0 WHERE id=:id",array(":id"=>$x['id']));
}

//clean tmp files
$f=scandir("tmp/");
$time=time();
foreach($f as $x){
	if(strlen($x)<5&&substr($x,0,1)==".") continue;
	$pid_time=filemtime("tmp/$x");
	if($time-$pid_time>7200) @unlink("tmp/$x");
}


//recheck the last blocks
if($_config['sanity_recheck_blocks']>0){
	$blocks=array();
	$all_blocks_ok=true;
	$start=$current['height']-$_config['sanity_recheck_blocks'];
	if($start<2) $start=2;
	$r=$db->run("SELECT * FROM blocks WHERE height>=:height ORDER by height ASC",array(":height"=>$start));
	foreach($r as $x){
			$blocks[$x['height']]=$x;
			$max_height=$x['height'];
	}
	
	for($i=$start+1;$i<=$max_height;$i++){
			$data=$blocks[$i];

			$key=$db->single("SELECT public_key FROM accounts WHERE id=:id",array(":id"=>$data['generator']));

			if(!$block->mine($key,$data['nonce'], $data['argon'], $data['difficulty'], $blocks[$i-1]['id'], $blocks[$i-1]['height'])) {
					$db->run("UPDATE config SET val=1 WHERE cfg='sanity_sync'");
					_log("Invalid block detected. Deleting everything after $data[height] - $data[id]");
					sleep(10);
					$all_blocks_ok=false;
					$block->delete($i);

					$db->run("UPDATE config SET val=0 WHERE cfg='sanity_sync'");
					break;
			}
	}
	if($all_blocks_ok) echo "All checked blocks are ok\n";
}


@unlink("tmp/sanity-lock");
?>
