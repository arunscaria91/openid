<?php

/* * ******************************************************************** */
/* ATutor							          */
/* * ******************************************************************** */
/* Copyright (c) 2002-2012                                                */
/* Inclusive Design Institute	                                          */
/* http://atutor.ca                                                       */
/*                                      			          */
/* This program is free software. You can redistribute it and/or          */
/* modify it under the terms of the GNU General Public License            */
/* as published by the Free Software Foundation.                          */
/* * ******************************************************************** */
//$Id: login.php UTF-8 10055 Jun 13, 2012 7:08:49 PM   Author:scari        $

$_user_location	= 'public';

define('AT_INCLUDE_PATH', '../../../include/');
require (AT_INCLUDE_PATH.'vitals.inc.php');
#importing required files
require ('openid_utility.php');
require ('../openid.vitals.inc.php');

#callback URL
define('CALLBACK_URL',"http://".$_SERVER['SERVER_NAME'].AT_BASE_HREF."mods/openid/login.php" );
define('GOOGLE_IDENTITY_URL','https://www.google.com/accounts/o8/id');
define('OPENID_LOGIN_PAGE_URL',AT_BASE_HREF.'mods/openid/openid_login.php');
define('OPENID_MOD_DIR', AT_BASE_HREF.'mods/openid/');

#Disable openid if master list is enabled.
if (defined('AT_MASTER_LIST') && AT_MASTER_LIST) {
    $msg->addError('OPENID_MASTERLIST_ENABLED');
    header('Location: '.AT_BASE_HREF.'login.php');
    exit;
}

    try{
        
        #Create OpenID Utility object
        $openid = new OpenIDUtility;        
        if (isset($_GET['login']) && $_GET['login']=='true' && 
            isset($_GET['openid_provider']) &&  $_GET['openid_provider'] == 'google' &&
            !$openid->mode) {
                  #Set Google indentity URL
                  $openid->identity = GOOGLE_IDENTITY_URL;
                  #setting call back url
                  //$openid->returnUrl = CALLBACK_URL;
                  #Select openid mode as 'checkid_setup'
                  $immediate_mode = ($_openid_config['OPENID_GOOGLE_MODE'] == 'checkid_immediate')?  false : true;
                  #Set the required ax params.
                  $openid->required = array(
                        'email'       =>   'contact/email',
                        'firstname'   =>   'namePerson/first',
                        'lastname'    =>   'namePerson/last',
                        /*'country'     =>   'contact/country/home',
                        'language'    =>   'pref/language'*/
                      );
                                
                  #Set UI params
                  if($_openid_config['OPENID_GOOGLE_ENABLE_UI_MODE'] != 'false'){
                       $openid->use_ui = true;
                       $openid->display_favicon = $_openid_config['OPENID_GOOGLE_UI_ICON'];
                       $openid->ui_mode= $_openid_config['OPENID_GOOGLE_UI_MODE'];
                  }else
                      $openid->use_ui = false;
                 
                                
                  #Set pape params
                  if($_openid_config['OPENID_GOOGLE_ENABLE_PAPE'] == 'true'){
                      $openid->use_pape = true;
                      $openid->max_auth_age = $_openid_config['OPENID_GOOGLE_MAX_AUTH_AGE'];
                  }else
                      $openid->use_pape = false;
                  
                  /*
                   * 
                   * The OAuth is inactive now. But may active in future.
                   * 
                  #Set ext2(OAuth 2.0) params
                  $oauth_str = '';
                  if($_openid_config['OPENID_GOOGLE_REQUEST_OAUTH'] == 'true' 
                          && $_openid_config['OPENID_GOOGLE_OAUTH_CONSUMER_KEY'] != ''){
                      #TODO: Add scope.
                      $oauthNs = urlencode("http://specs.openid.net/extensions/oauth/1.0");
                      $scope = urlencode('http://gdata.youtube.com'.' '.
                               'https://docs.google.com/feeds/'.' '.
                               'https://www.google.com/calendar/feeds/');
                      $oauth_str = '&openid.ns.ext2='.$oauthNs.
                                             '&openid.ext2.consumer='.urlencode($_openid_config['OPENID_GOOGLE_OAUTH_CONSUMER_KEY']).
                                             '&openid.ext2.scope='.$scope;
                  }*/
                                
                  /*
                  * Now everything is set. Find the end point with OpenID
                  * discovery and redirect to the authentication url,
                  */
                  
                  header('Location: ' . $openid->authUrl() .$oauth_str);
                  exit;
         }
         else if($openid->mode=='cancel'){
            
            #TODO: Should `cancel` be considered as failed login attempt?
            $msg->addError('OPENID_USER_CANCELLED_REQUEST');
            header('Location: ' .OPENID_LOGIN_PAGE_URL);
            exit;
            
         }
         else{
             #Google has granted the details. Now, Validate the details before applying it.
             $is_valid = $openid->validate();
             if($is_valid){
                 $attr = $openid->getAttributes();
                 $openid_email    = isset($attr['contact/email'])?$attr['contact/email']:NULL;
                 $openid_fname    = isset($attr['namePerson/first'])?$attr['namePerson/first']:NULL;
                 $openid_lname    = isset($attr['namePerson/last'])?$attr['namePerson/last']:NULL;
                 $openid_language = isset($attr['pref/language'])?$attr['pref/language']:NULL;
                 $openid_country  = isset($attr['contact/country/home'])?$attr['contact/country/home']:NULL;
                 if(!$openid_email && !$openid_fname && !$openid_lname)
                     throw ErrorException("Failed to retrieve required attributes from OpenID provider.");
                 
                 #Check whether user exists in db
                 $result = mysql_query("SELECT member_id, login, status, preferences, language, last_login FROM ".TABLE_PREFIX."members WHERE email='$openid_email'",$db);
                 if(!$result){
                     throw ErrorException('Invalid MySQL query : '. mysql_error(),  mysql_errno());
                 }
                 
                 if(mysql_num_rows($result)==0){
                     #Email doesn't exist in DB. Register the user.
                                         
                     #Is registration allowed??
                     if($_config['allow_registration'] != 1){
                         $msg->addInfo('REG_DISABLED');
                         header('Location: '.OPENID_LOGIN_PAGE_URL);
                         exit;
                     }
                     
                                          
                     #No Email conformation is needed for OpenID login.
                     $status = AT_STATUS_STUDENT;
                     $now = date('Y-m-d H:i:s');
                     $default_private_email = 1;
                     
                     #Its time to select a login name.
                     $login_name = getFreeLoginName($db, $openid_fname, $openid_lname,$openid_email);

                     $sql = "INSERT INTO ".TABLE_PREFIX."members 
		              (login,
		               email,
		               first_name,
		               last_name,
		               country,
		               status,
                               preferences,
		               creation_date,
		               language,
		               inbox_notify,
		               private_email,
		               last_login)
		       VALUES ('$login_name',
		               '$openid_email',
		               '$openid_fname',
                               '$openid_lname',
		               '$openid_country', 
		                $status, 
		               '$_config[pref_defaults]', 
		               '$now',
		               '$_SESSION[lang]', 
		                $_config[pref_inbox_notify], 
		                $default_private_email, 
		               '0000-00-00 00:00:00')";
                     
                     $result = mysql_query($sql, $db) or die(mysql_error());
                     $m_id   = mysql_insert_id($db);
                     if (!$result) {
			 $msg->addError('DB_NOT_UPDATED');
			 header('Location: '.OPENID_LOGIN_PAGE_URL);
                         exit;
                     }else{
                         #Reset login attempts.
                         $sql = "DELETE FROM ".TABLE_PREFIX."member_login_attempt WHERE login='$login_name'";
			 mysql_query($sql, $db);
 
                         #If en_id is set, automatically enroll into courses that links with en_id and go to "My Start Page"
			 $member_id = $m_id;
                         $default_course_id = 0;

                         require (AT_INCLUDE_PATH.'html/auto_enroll_courses.inc.php');
			
			 #Update last_login
			 $sql = "UPDATE ".TABLE_PREFIX."members 
			           SET last_login=now(), creation_date=creation_date 
			         WHERE member_id=".$member_id;
			 mysql_query($sql, $db);
			
			 #Write to session.
			 $_SESSION['valid_user'] = true;
			 $_SESSION['member_id']	= $m_id;
			 $_SESSION['course_id']  = 0;
			 $_SESSION['login'] = $login_name;
			 assign_session_prefs(unserialize(stripslashes($_config[pref_defaults])), 1);
			 $_SESSION['is_guest']	= 0;
			 $_SESSION['lang'] = $_SESSION[lang];
                         $_SESSION['first_login'] = true;
                         
                         #Its an OpenID session
                         $_SESSION['is_openid'] = true;
                         $_SESSION['openid_claimed_id'] = $openid->identity;
			 session_write_close();

			 header('Location: '.AT_BASE_HREF.'bounce.php?course='.$default_course_id);
                         exit;
                         
                     }
                 }else{
                     #User has already registered. It's time to login.
                     
                     #Garbage collect for maximum login attempts table
                     if (rand(1, 100) == 1){
                        $sql = 'DELETE FROM '.TABLE_PREFIX.'member_login_attempt WHERE expiry < '. time();
                        mysql_query($sql, $db);
                     }
                     
                     if (version_compare(PHP_VERSION, '5.1.0', '>=')) {
                        session_regenerate_id(TRUE);
                     }
                     
                     #Get the login name, member id, status and preferences.
                     list($m_id, $login_name, $status, $preferences, $language, $last_login) = mysql_fetch_array($result);
                     
                     #Check if this account has exceeded maximum attempts
                     $sql = 'SELECT login, attempt, expiry FROM '.TABLE_PREFIX."member_login_attempt WHERE login='$login_name'";

                     $result = mysql_query($sql, $db);
	             if ($result && mysql_numrows($result) > 0){
                         list($attempt_login_name, $attempt_login, $attempt_expiry) = mysql_fetch_array($result);
                     } else {
                         $attempt_login_name = '';
                         $attempt_login = 0;
                         $attempt_expiry = 0;
                     }
                     
                     if($attempt_expiry > 0 && $attempt_expiry < time()){
                        
                         #Clear entry if it has expired
                         $sql = 'DELETE FROM '.TABLE_PREFIX."member_login_attempt WHERE login='$login_name'";
                         mysql_query($sql, $db);
                         $attempt_login = 0;	
                         $attempt_expiry = 0;
                     }
                     
                     if($_config['max_login'] > 0 && $attempt_login >= $_config['max_login']){
                         $msg->addError('MAX_LOGIN_ATTEMPT');
                         header('Location: ' .OPENID_MOD_DIR.'openid_login.php');
                         exit;
                     } else if ($status == AT_STATUS_UNCONFIRMED) {
                         $msg->addError('NOT_CONFIRMED');
                         #TODO: Make the mail id as conformed.
                         header('Location: ' .OPENID_MOD_DIR.'openid_login.php');
                         exit;
                     } else if ($status == AT_STATUS_DISABLED) {
                         $msg->addError('ACCOUNT_DISABLED');
                         header('Location: ' .OPENID_MOD_DIR.'openid_login.php');
                         exit;
                     }else{
                         
                         #Everythings OK. Make Login.
                         $_SESSION['valid_user'] = true;
                         $_SESSION['member_id']  = intval($m_id);
                         $_SESSION['login']	 = $login_name;
                         if ($preferences == "")
                            assign_session_prefs(unserialize(stripslashes($_config["pref_defaults"])), 1);
                         else
                            assign_session_prefs(unserialize(stripslashes($preferences)), 1);
                         $_SESSION['is_guest']	= 0;
                         $_SESSION['lang']	= $language;
                         $_SESSION['course_id']  = 0;
                         $now = date('Y-m-d H:i:s');
                         $default_course_id = 0;
                         
                         #Its an OpenID session
                         $_SESSION['is_openid'] = true;
                         $_SESSION['openid_claimed_id'] = $openid->identity;
                         
                         $_SESSION['first_login'] = false;
                         if ($last_login == null || $last_login == '' 
                            || $last_login == '0000-00-00 00:00:00' 
                            || $_SESSION['prefs']['PREF_MODIFIED']!==1) {
                                
                             $_SESSION['first_login'] = true;
                         }

                         $sql = "UPDATE ".TABLE_PREFIX."members SET creation_date=creation_date, last_login='$now' WHERE member_id=$_SESSION[member_id]";
                         mysql_query($sql, $db);

                         #Clear login attempt on successful login
                         $sql = 'DELETE FROM '.TABLE_PREFIX."member_login_attempt WHERE login='$login_name'";
                         mysql_query($sql, $db);
		
                         $msg->addFeedback('LOGIN_SUCCESS');
                            
                         header('Location: '.AT_BASE_HREF.'bounce.php?course='.$default_course_id);
                         exit;
                    }
                 }
                 
                 #User has failed to login. Unset the session.
                 unsetSession(); 
                 
             }
             else{
                 
                 #User has failed to login. Unset the session.
                 unsetSession(); 
                 
                 #invalid credentials are detected.
                 $msg->addError('OPENID_INVALID_LOGIN');
                 header('Location: ' .AT_BASE_HREF.'mods/openid/openid_login.php');
                 exit;
             }
         }
    }catch (Exception $e){
 
       #User has failed to login. Unset the session.
       unsetSession(); 
       
       $msg->addError(array('OPENID_EXCEPTION_OCCURED',$e->getMessage(),$e->getCode()));
       header('Location: ' .AT_BASE_HREF.'mods/openid/openid_login.php');
       exit;
    }


?>


