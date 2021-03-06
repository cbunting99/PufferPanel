<?php
/*
    PufferPanel - A Minecraft Server Management Panel
    Copyright (c) 2013 Dane Everitt
 
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
 
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
 
    You should have received a copy of the GNU General Public License
    along with this program.  If not, see http://www.gnu.org/licenses/.
 */
session_start();
require_once('../../../src/framework/framework.core.php');

/*
 * TOTP Class
 */
use Otp\Otp;
use Otp\GoogleAuthenticator;
use Base32\Base32;

if($core->auth->isLoggedIn($_SERVER['REMOTE_ADDR'], $core->auth->getCookie('pp_auth_token'), $core->auth->getCookie('pp_server_hash')) === true){

	if(isset($_POST['action']) && $_POST['action'] == 'generate'){
	
		/*
		 * Generate the TOTP Token
		 */
		$secret = GoogleAuthenticator::generateRandom();
		
		$updateTOTPSettings = $mysql->prepare("UPDATE `users` SET `use_totp` = 0, `totp_secret` = :secret WHERE `id` = :uid");
		$updateTOTPSettings->execute(array(
			'secret' => $secret,
			'uid' => $core->user->getData('id')
		));
		
		/*
		 * Generate QR Code
		 */
		$url = GoogleAuthenticator::getQrCodeUrl('totp', $core->user->getData('email'), $secret);
		
		/*
		 * Display QR Code and Verification Form
		 */
		echo '
			<div class="row" id="notice_box_totp" style="display:none;"></div>
			<div class="row">
				<div class="col-4">
					<center><img src="'.$url.'" /><br /><br /><code>'.$secret.'</code></center>
				</div>
				<div class="col-8">
					<div class="alert alert-info">Please verify your TOTP settings by scanning the QR Code to the right with your phone\'s authenticator application, and then enter the 6 number code generated by the application in the box below. <strong>Press the enter key when finished.</strong></div>
					<form action="#" method="post" id="totp_token_verify">
						<div class="form-group">
							<label class="control-label" for="totp_token">TOTP Token</label>
							<input class="form-control input-lg" type="text" id="totp_token" style="font-size:30px;" />
						</div>
					</form>
				</div>
			</div>
		';
		exit();
		
	}else if(isset($_POST['token'], $_GET['action']) && $_GET['action'] == 'verify'){
	
		if($core->auth->validateTOTP($_POST['token'], $core->user->getData('totp_secret')) === true){
		
			$updateTOTPSettings = $mysql->prepare("UPDATE `users` SET `use_totp` = 1 WHERE `id` = :uid");
			$updateTOTPSettings->execute(array(
				'uid' => $core->user->getData('id')
			));
			
			echo '<div class="alert alert-success"><strong>Your account has been enabled with TOTP verification. Please click the close button on this box to finish.</strong></div>';
			exit();
		
		}else{
		
			echo '<div class="alert alert-danger"><strong>Unable to verify your TOTP token. Please try again.</strong></div>';
			exit();
		
		}
	
	}else{ exit('<div class="alert alert-danger"><strong>Invalid access to this page.</strong></div>'); }

}

?>
