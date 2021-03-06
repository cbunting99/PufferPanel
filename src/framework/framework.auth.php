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
namespace Auth;

/*
 * TOTP Class
 */
use Otp\Otp;
use Otp\GoogleAuthenticator;
use Base32\Base32;

/**
 * PufferPanel Core Components Trait
 */
trait components {

	/**
	 * Returns a hashed version of the raw string that is passed. Use for password hashing.
	 *
	 * @param string $raw The raw password.
	 * @return string The hashed password.
	 */
	public function hash($raw){

		return password_hash($raw, PASSWORD_BCRYPT);

	}

	/**
	 * Compares a password to the hashed version to see if they match.
	 *
	 * @param string $raw The raw password.
	 * @param string $hashed The hashed password.
	 * @return bool Returns true if the password matches.
	 */
	private function password_compare($raw, $hashed){

		if(password_verify($raw, $hashed))
			return true;
		else
			return false;

	}

	/**
	 * Generates an OpenSSL Encryption initalization vector.
	 *
	 * @return string
	 */
	public function generate_iv(){

		return base64_encode(mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_CAST_256, MCRYPT_MODE_CBC), MCRYPT_RAND));

	}

	/**
	 * Encrypts a given string using an IV and defined method.
	 *
	 * @param string $raw The raw string to be encrypted.
	 * @param string $iv The initalization vector to use.
	 * @param string $method Defaults to AES-256-CBC but you can define any other valid encryption method.
	 * @return string
	 * @static
	 */
	public static function encrypt($raw, $iv, $method = 'AES-256-CBC'){

		return openssl_encrypt($raw, $method, file_get_contents(HASH), false, base64_decode($iv));

	}

	/**
	 * Decrypts a given string using an IV and defined method.
	 *
	 * @param string $encrypted The encrypted string that you want decrypted.
	 * @param string $iv The initalization vector to use.
	 * @param string $method Defaults to AES-256-CBC but you can define any other valid encryption method.
	 * @return string
	 * @static
	 */
	public static function decrypt($encrypted, $iv, $method = 'AES-256-CBC'){

		return openssl_decrypt($encrypted, $method, file_get_contents(HASH), 0, base64_decode($iv));

	}

	/**
	 * Generate RFC 4122 Compliant v4 UUIDs
	 *
	 * @return string Returns a RFC 412 compliant UUID in the format XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX
	 * @static
	 */
	public static function gen_UUID(){

		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff),
						mt_rand(0, 0xffff),
						mt_rand(0, 0x0fff) | 0x4000,
						mt_rand(0, 0x3fff) | 0x8000,
						mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

	}

	/**
	 * Generates a random string of characters.
	 *
	 * @param int $amount
	 * @return string
	 * @static
	 */
	public static function keygen($amount){

		$keyset  = "abcdefghijklmnpqrstuvwxyzABCDEFGHIJKLMNPQRSTUVWXYZ0123456789";

		$randkey = null;
		$maxLength = (strlen($keyset) - 1);

		for ($i=0; $i < $amount; $i++)
			$randkey .= $keyset[mt_rand(0, $maxLength)];

		return str_shuffle($randkey);

	}

	/**
	 * Returns the specified cookie.
	 *
	 * @param string $cookie
	 * @return mixed
	 */
	public function getCookie($cookie){

		if(isset($_COOKIE[$cookie])){

			return $_COOKIE[$cookie];

		}else{

			return null;

		}

	}

}

/**
 * PufferPanel Core Authentication Class
 */
class auth {

	use \Database\database, \Auth\components, \Page\components;

	/**
	 * Authentcation constructor class.
	 *
	 * @return void
	 */
	public function __construct()
		{

			$this->mysql = self::connect();
			$this->settings = new \settings();

		}

	/**
	 * Validates a TOTP request.
	 *
	 * @todo Prevent TOTP replay attack.
	 * @param string $key The TOTP token sent in.
	 * @param string $secret The TOTP secret.
	 * @return bool
	 */
	public function validateTOTP($key, $secret){

		$otp = new Otp();

		if($otp->checkTotp(Base32::decode($secret), $key))
			return true;
		else
			return false;

	}

	/**
	 * Verifys the a user is entering the correct password for their account.
	 *
	 * @param string $email
	 * @param string $raw The raw password.
	 * @return bool
	 */
	public function verifyPassword($email, $raw){

		$this->get = $this->mysql->prepare("SELECT `password` FROM `users` WHERE `email` = :email");
		$this->get->execute(array(
			':email' => $email
		));

			if($this->get->rowCount() == 1){

				$this->row = $this->get->fetch();
				return $this->password_compare($raw, $this->row['password']);

			}else
				return false;

	}

	/**
	 * Checks if a user is currently logged in or if their session is expired.
	 *
	 * @param string $ip
	 * @param string $session
	 * @param string $serverhash
	 * @param int $acp
	 * @return bool
	 */
	public function isLoggedIn($ip, $session, $serverhash = null, $acp = false){

		$this->query = $this->mysql->prepare("SELECT * FROM `users` WHERE `session_ip` = :sessip AND `session_id` = :session");
		$this->query->execute(array(
			':sessip' => $ip,
			':session' => $session
		));

		if($this->query->rowCount() == 1){

			$this->row = $this->query->fetch();

			if($this->row['root_admin'] != 1 && $acp === true)
				return false;
			else{

				if($this->row['root_admin'] != '1'){

					if(!is_null($serverhash)){

						$this->_validateServer = $this->mysql->prepare("SELECT * FROM `servers` WHERE `hash` = :shash AND `owner_id` = :ownerid AND `active` = 1");
						$this->_validateServer->execute(array(
							':shash' => $serverhash,
							':ownerid' => $this->row['id']
						));

							if($this->_validateServer->rowCount() == 1)
								return true;
							else
								return false;

					}else
						return true;

				}else
					return true;

			}

		}else
			return false;

	}

}

?>
