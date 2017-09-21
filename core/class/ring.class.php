<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class ring extends eqLogic {

  public function updateUser() {
    if (substr(config::byKey('username','ring'),0,1) != '+') {
      log::add('ring', 'error', 'Nom utilisateur mal formé, vous devez saisir +33...');
      return;
    }
    ring::authCloud();
    $url = 'utilisateur/get/' . urlencode(config::byKey('username','ring'));
    $json = ring::callCloud($url);
    foreach ($json['data']['serrures'] as $key) {
      $ring = ring::byLogicalId($key['id_serrure'], 'ring');
      if (!is_object($ring)) {
        $ring = new ring();
        $ring->setEqType_name('ring');
        $ring->setLogicalId($key['id_serrure']);
        $ring->setName('Serrure ' . $key['nom']);
        $ring->setIsEnable(1);
        $ring->setConfiguration('type', 'locker');
        $ring->setConfiguration('id', $key['id']);
        $ring->setConfiguration('id_serrure', $key['id_serrure']);
        $ring->setConfiguration('code', $key['code']);
        $ring->setConfiguration('code_serrure', $key['code_serrure']);
        $ring->setConfiguration('serrure_droite', $key['serrure_droite']);
        //$ring->setConfiguration('etat', $key['etat']);
        $ring->setConfiguration('couleur', $key['couleur']);
        $ring->setConfiguration('public_key', $key['public_key']);
        $ring->setConfiguration('nom', $key['nom']);
        //$ring->setConfiguration('battery', $key['battery']);
        $ring->save();
        event::add('ring::found', array(
          'message' => __('Nouvelle serrure ' . $key['nom'], __FILE__),
        ));
      }
      $ringCmd = ringCmd::byEqLogicIdAndLogicalId($ring->getId(),'status');
      if (!is_object($ringCmd)) {
        $ring->loadCmdFromConf($ring->getConfiguration('type'));
      }
      $value = ($key['etat'] == 'open') ? 0:1;
      $ring->checkAndUpdateCmd('status',$value);
      $ring->checkAndUpdateCmd('battery',$key['battery']/1000);
      $ring->batteryStatus($key['battery']/40);
      log::add('ring', 'debug', 'Serrure ' . $key['nom'] . ' statut ' . $key['etat'] . ' ' . $value . ' batterie ' . $key['battery']);
    }
  }

  public function scanLockers() {
    if (!$this->pingHost()) {
      log::add('ring', 'debug', 'Erreur de connexion gateway');
      return;
    }
    $idgateway = $this->getConfiguration('idfield');
    $url = 'http://' . $this->getConfiguration('ipfield') . '/lockers';
    $request_http = new com_http($url);
    $output = $request_http->exec(30);
    log::add('ring', 'debug', 'Scan : ' . $output);
    $json = json_decode($output, true);
    log::add('ring', 'debug', 'Scan : ' . $url);
    foreach ($json['devices'] as $device) {
      $ring = ring::byLogicalId($device['identifier'], 'ring');
      if (is_object($ring)) {
        $ring->setConfiguration('rssi',$device['rssi']);
        $ring->save();
        //createCmds for this gateway
        $ring->checkCmdOk($idgateway, 'open', 'locker', 'Déverrouillage avec ' . $this->getName());
        $ring->checkCmdOk($idgateway, 'close', 'locker', 'Verrouillage avec ' . $this->getName());
        $ring->checkAndUpdateCmd('battery',$device['battery']/1000);
        $ring->batteryStatus($device['battery']/40);
        log::add('ring', 'debug', 'Rafraichissement serrure : ' . $device['identifier'] . ' ' . $device['battery'] . ' ' . $device['rssi']);
      }
    }
    $url = 'http://' . $this->getConfiguration('ipfield') . '/synchronize';
    $request_http = new com_http($url);
    $output = $request_http->exec(30);
    log::add('ring', 'debug', 'Synchronise : ' . $url . ' ' . $output);
  }

  public function cmdsShare() {
    foreach (eqLogic::byType('ring', true) as $keyeq) {
      if ($keyeq->getConfiguration('type') == 'locker') {
        $this->checkCmdOk($keyeq->getLogicalId(), 'enable', $this->getConfiguration('type'), 'Activer partage avec ' . $keyeq->getName());
        $this->checkCmdOk($keyeq->getLogicalId(), 'unable', $this->getConfiguration('type'), 'Désactiver partage avec ' . $keyeq->getName());
        $this->checkCmdOk($keyeq->getLogicalId(), 'status', $this->getConfiguration('type'), 'Statut partage avec ' . $keyeq->getName());
      }
    }
  }

  public function checkShare() {
    if (substr(config::byKey('username','ring'),0,1) != '+') {
      return;
    }
    ring::authCloud();
    $accessoire = array();
    $phone = array();
    foreach (eqLogic::byType('ring', true) as $keyeq) {
      if ($keyeq->getConfiguration('type') == 'gateway') {
        $accessoire[$keyeq->getConfiguration('idfield')] = array();
      }
      if ($keyeq->getConfiguration('type') == 'phone') {
        $phone[$keyeq->getConfiguration('idfield')] = array();
      }
      if ($keyeq->getConfiguration('type') == 'button') {
        $accessoire[$keyeq->getConfiguration('idfield')] = array();
      }
    }
    log::add('ring', 'debug', 'Accessoire : ' . print_r($accessoire,true));
    foreach (eqLogic::byType('ring', true) as $keyeq) {
      if ($keyeq->getConfiguration('type') == 'locker') {
        $url = 'partage/all/serrure/' . $keyeq->getConfiguration('id');
        $json = ring::callCloud($url);
        foreach ($json['data']['partages_accessoire'] as $share) {
          log::add('ring', 'debug', 'Partage serrure : ' . $share['accessoire']['id_accessoire'] . ' ' . $share['code']);
          if (!(isset($share['date_debut']) || isset($share['date_fin']) || isset($share['heure_debut']) || isset($share['heure_fin']))) {
            //on vérifier que c'est un partage permanent, jeedom ne prend pas en compte les autres
            $accessoire[$share['accessoire']['id_accessoire']][$keyeq->getConfiguration('id')]['id'] = $share['id'];
            $accessoire[$share['accessoire']['id_accessoire']][$keyeq->getConfiguration('id')]['code'] = $share['code'];
            //on sauvegarde le statut si bouton/phone, si gateway on s'assure d'etre en actif
            $eqtest = ring::byLogicalId($share['accessoire']['id_accessoire'], 'ring');
            if (is_object($eqtest)) {
              if ($eqtest->getConfiguration('type') == 'gateway' && !$share['actif']) {
                $keyeq->editShare($share['id'], $share['accessoire']['id_accessoire']);
              }
              if ($eqtest->getConfiguration('type') == 'phone' || $eqtest->getConfiguration('type') == 'button') {
                $value = ($share['actif']) ? 1:0;
                $eqtest->checkAndUpdateCmd('status-'.$keyeq->getLogicalId(), $value);
              }
            }
            if ($share['accessoire']['type'] == '2') {
              $eqtest = ring::byLogicalId($share['accessoire']['id_accessoire'] . '-' . $share['id'], 'ring');
              if (!is_object($eqtest)) {
                log::add('ring', 'debug', 'Digicode trouvé');
                $eqtest = new ring();
                $eqtest->setEqType_name('ring');
                $eqtest->setLogicalId($share['accessoire']['id_accessoire'] . '-' . $share['id']);
                $eqtest->setName('Digicode sur ' . $share['accessoire']['nom'] . ' avec ' . $share['code']);
                $eqtest->setIsEnable(1);
                $eqtest->setConfiguration('type', 'digicode');
                $eqtest->setConfiguration('id_share', $share['id']);
                $eqtest->setConfiguration('id_serrure', $keyeq->getLogicalId());
                $eqtest->setConfiguration('id', $share['accessoire']['id_accessoire']);
                $eqtest->setConfiguration('code', $share['code']);
                $eqtest->save();
                $eqtest->checkCmdOk($share['id'], 'enable', 'digicode', 'Activer');
                $eqtest->checkCmdOk($share['id'], 'unable', 'digicode', 'Désactiver');
                $eqtest->checkCmdOk($share['id'], 'status', 'digicode', 'Statut');
                event::add('ring::found', array(
                  'message' => __('Nouveau partage digicode ' . $share['accessoire']['id_accessoire'], __FILE__),
                ));
              }
              log::add('ring', 'debug', 'Digicode satus : ' . $share['actif']);
              $value = ($share['actif']) ? 1:0;
              $eqtest->checkAndUpdateCmd('status-'.$share['id'], $value);
            }
          }
        }
        foreach ($accessoire as $id => $stuff) {
          //boucle pour vérifier si chaque gateway/bouton possède une entrée de partage avec l'équipement en cours, sinon on appelle le createShare et on ajoute le retour
          log::add('ring', 'debug', 'ID : ' . $id . ' ' . print_r($stuff,true));
          if (count($stuff) == 0) {
            log::add('ring', 'debug', 'Create Share : ' . $id . ' ' . print_r($stuff,true));
            $json = $keyeq->createShare($id);
            if (isset($json['data']['code'])) {
              $accessoire[$id]['id'] = $json['data']['id'];
              $accessoire[$id]['code'] = $json['data']['code'];
            }
          }
        }
        foreach ($json['data']['partages_utilisateur'] as $share) {
          log::add('ring', 'debug', 'Partage serrure : ' . $share['utilisateur']['username']);
          if (!(isset($share['date_debut']) || isset($share['date_fin']) || isset($share['heure_debut']) || isset($share['heure_fin']))) {
            //on vérifier que c'est un partage permanent, jeedom ne prend pas en compte les autres
            $phone[$share['utilisateur']['username']][$keyeq->getConfiguration('id')]['id'] = $share['id'];
            //$phone[$share['utilisateur']['username']][$keyeq->getConfiguration('id')]['code'] = $share['code'];
            $eqtest = ring::byLogicalId($share['utilisateur']['username'], 'ring');
            if (is_object($eqtest)) {
              $value = ($share['actif']) ? 1:0;
              $eqtest->checkAndUpdateCmd('status-'.$keyeq->getLogicalId(), $value);
              log::add('ring', 'debug', 'Partage serrure : ' . $share['utilisateur']['username']. 'status-'.$keyeq->getConfiguration('id') . ' ' . $value);
            }
          }
        }
        log::add('ring', 'debug', 'Phones trouvés : ' . print_r($phone,true));
        foreach ($phone as $id => $stuff) {
          //boucle pour vérifier si chaque gateway/bouton possède une entrée de partage avec l'équipement en cours, sinon on appelle le createShare et on ajoute le retour
          log::add('ring', 'debug', 'ID : ' . $id . ' ' . print_r($stuff,true));
          if (count($stuff) == 0) {
            log::add('ring', 'debug', 'Create Share : ' . $id . ' ' . print_r($stuff,true));
            $json = $keyeq->createShare($id,true);
            if (isset($json['data']['code'])) {
              $phone[$id]['id'] = $json['data']['id'];
              $phone[$id]['code'] = $json['data']['code'];
            }
          }
        }
      }
    }
    config::save('shares_accessoire', json_encode($accessoire),  'ring');
    config::save('shares_phone', json_encode($phone),  'ring');
  }

  public function createShare($_id, $_phone = false, $_digicode = '') {
    if (substr(config::byKey('username','ring'),0,1) != '+') {
      return;
    }
    ring::authCloud();
    if ($_phone) {
      $url = 'partage/create/' . $this->getConfiguration('id') . '/' . urlencode($_id);
      $data = array('partage[description]' => 'jeedom', 'partage[nom]' => 'jeedom' . str_replace('+','',$_id), 'partage[actif]' => 1);
    } else {
      $url = 'partage/create/' . $this->getConfiguration('id') . '/accessoire/' . $_id;
      $data = array('partage_accessoire[description]' => 'jeedom', 'partage_accessoire[nom]' => 'jeedom' . str_replace('+','',$_id), 'partage_accessoire[actif]' => 1);
      if ($_digicode != '') {
        $data['partage_accessoire[code]'] = $_digicode;
      }
    }
    $json = ring::callCloud($url,$data);
    return $json;
  }

  public function editShare($_id, $_eqId, $_actif = 'enable', $_phone = false, $_digicode = '') {
    if (substr(config::byKey('username','ring'),0,1) != '+') {
      return;
    }
    ring::authCloud();
    if ($_phone) {
      $url = 'partage/update/' . urlencode($_id);
      $data = array('partage[nom]' => 'jeedom' . str_replace('+','',$_id));
      if ($_actif == 'enable') {
        $data['partage[actif]'] = 1;
      }
    } else {
      $url = 'partage/accessoire/update/' . $_id;
      $data = array('partage_accessoire[nom]' => 'jeedom' . str_replace('+','',$_eqId));
      if ($_actif == 'enable') {
        $data['partage_accessoire[actif]'] = 1;
      }
    }
    if ($_digicode != '') {
      $data['partage_accessoire[code]'] = $_digicode;
    }
    log::add('ring', 'debug', 'ID : ' . $_id . ' ' . $_actif . ' ' . print_r($data,true));
    $json = ring::callCloud($url,$data);
    return $json;
  }

  public function postAjax() {
    if ($this->getConfiguration('type') != 'locker') {
      $this->setConfiguration('type',$this->getConfiguration('typeSelect'));
      $this->setLogicalId($this->getConfiguration('idfield'));
      $this->save();
    }
    if ($this->getConfiguration('type') == 'gateway') {
      $this->loadCmdFromConf($this->getConfiguration('type'));
      $this->save();
      $this->scanLockers();
      event::add('ring::found', array(
        'message' => __('Nouveau gateway ' . $this->getName(), __FILE__),
      ));
    }
    if ($this->getConfiguration('type') == 'button' || $this->getConfiguration('type') == 'phone') {
      $this->cmdsShare();
    }
    self::updateUser();
    self::checkShare();
  }

  public function loadCmdFromConf($type) {
    if (!is_file(dirname(__FILE__) . '/../config/devices/' . $type . '.json')) {
      return;
    }
    $content = file_get_contents(dirname(__FILE__) . '/../config/devices/' . $type . '.json');
    if (!is_json($content)) {
      return;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      return true;
    }
    $this->import($device);
  }

  public function checkCmdOk($_id, $_value, $_category, $_name) {
    $ringCmd = ringCmd::byEqLogicIdAndLogicalId($this->getId(),$_value . '-' . $_id);
    if (!is_object($ringCmd)) {
      log::add('ring', 'debug', 'Création de la commande ' . $_value . '-' . $_id);
      $ringCmd = new ringCmd();
      $ringCmd->setName(__($_name, __FILE__));
      $ringCmd->setEqLogic_id($this->getId());
      $ringCmd->setEqType('ring');
      $ringCmd->setLogicalId($_value . '-' . $_id);
      if ($_value == 'status') {
        $ringCmd->setType('info');
        $ringCmd->setSubType('binary');
        $ringCmd->setTemplate("mobile",'lock' );
        $ringCmd->setTemplate("dashboard",'lock' );
      } else {
        $ringCmd->setType('action');
        $ringCmd->setSubType('other');
        if ($_value == 'open' || $_value == 'enable') {
          $ringCmd->setDisplay("icon",'<i class="fa fa-unlock"></i>' );
        } else {
          $ringCmd->setDisplay("icon",'<i class="fa fa-lock"></i>' );
        }
      }
      $ringCmd->setConfiguration('value', $_value);
      $ringCmd->setConfiguration('id', $_id);
      $ringCmd->setConfiguration('category', $_category);
      if ($_category == 'locker') {
        $ringCmd->setConfiguration('gateway', $_id);
      }
      $ringCmd->save();
    }
  }

  public function cron() {
    //scan des lockers par les gateways toutes les 15mn
    foreach (eqLogic::byType('ring', true) as $keyeq) {
      if ($keyeq->getConfiguration('type') == 'gateway') {
        $keyeq->scanLockers();
      }
    }
  }

  public function cron30() {
    //update des infos de l'API (lockers existants, batterie, status) + verification que les share sont existants
    ring::updateUser();
    ring::checkShare();
  }

  public function pageConf() {
    //sur sauvegarde page de conf update des infos de l'API (lockers existants, batterie, status) + verification que les share sont existants
    ring::updateUser();
    ring::checkShare();
  }

  public function pingHost () {
    $connection = @fsockopen($this->getConfiguration('ipfield'), 80);
    if (is_resource($connection)) {
      $result = true;
      $this->checkAndUpdateCmd('online', 1);
    } else {
      $result = false;
      $this->checkAndUpdateCmd('online', 0);
    }
    return $result;
  }

  public function callGateway($uri,$id = '', $code = '') {
    if (!$this->pingHost()) {
      log::add('ring', 'debug', 'Erreur de connexion gateway');
      return;
    }
    $url = 'http://' . $this->getConfiguration('ipfield') . '/' . $uri;
    log::add('ring', 'debug', 'URL : ' . $url);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL,$url);
    curl_setopt($curl, CURLOPT_POST, 1);
    if ($uri != ' lockers') {
      ini_set('date.timezone', 'UTC');
      $ts = time();
      $key = hash_hmac('sha256',$ts,$code,true);
      $hash = base64_encode($key);
      $fields = array('hash' => $hash, 'identifier' => $id, 'ts' => $ts);
      $fields_string = '';
      foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
      rtrim($fields_string, '&');
      curl_setopt($curl,CURLOPT_POST, count($fields));
      curl_setopt($curl,CURLOPT_POSTFIELDS, $fields_string);
      log::add('ring', 'debug', 'Array : ' . print_r($fields, true));
    }
    curl_setopt($curl,CURLOPT_RETURNTRANSFER , 1);
    $json = json_decode(curl_exec($curl), true);
    curl_close ($curl);
    log::add('ring', 'debug', 'Retour : ' . print_r($json, true));
    return;
  }

  public function callCloud($url,$data = array('format' => 'json')) {
    $url = 'https://api.the-keys.fr/fr/api/v2/' . $url;
    if (isset($data['format'])) {
      $url .= '?_format=' . $data['format'];
    }
    if (time() > config::byKey('timestamp','ring')) {
      ring::authCloud();
    }
    $request_http = new com_http($url);
    $request_http->setHeader(array('Authorization: Bearer ' . config::byKey('token','ring')));
    if (!isset($data['format'])) {
      $request_http->setPost($data);
    }
    $output = $request_http->exec(30);
    $json = json_decode($output, true);
    log::add('ring', 'debug', 'URL : ' . $url);
    //log::add('ring', 'debug', 'Authorization: Bearer ' . config::byKey('token','ring'));
    log::add('ring', 'debug', 'Retour : ' . $output);
    return $json;
  }

  public function authCloud() {
    $url = 'https://api.the-keys.fr/api/login_check';
    $user = config::byKey('username','ring');
    $pass = config::byKey('password','ring');
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL,$url);
    curl_setopt($curl, CURLOPT_POST, 1);
    $headers = [
      'Content-Type: application/x-www-form-urlencoded'
    ];
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $fields = array(
      '_username' => urlencode($user),
      '_password' => urlencode($pass),
    );
    $fields_string = '';
    foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
    rtrim($fields_string, '&');
    curl_setopt($curl,CURLOPT_POST, count($fields));
    curl_setopt($curl,CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($curl,CURLOPT_RETURNTRANSFER , 1);
    $json = json_decode(curl_exec($curl), true);
    curl_close ($curl);
    $timestamp = time() + (2 * 60 * 60);
    config::save('token', $json['token'],  'ring');
    config::save('timestamp', $timestamp,  'ring');
    //log::add('ring', 'debug', 'Retour : ' . print_r($json, true));
    return;
  }

}

class ringCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getType() == 'info') {
      return;
    }
    switch ($this->getConfiguration('category')) {
      case 'locker' :
      $eqLogic = $this->getEqLogic();
      $gatewayid = $this->getConfiguration('gateway');
      $gateway = ring::byLogicalId($gatewayid, 'ring');
      $key = config::byKey('shares_accessoire','ring');
      //log::add('ring', 'debug', 'Config : ' . print_r(config::byKey('shares_accessoire','ring'),true));
      $code = $key[$gatewayid][$eqLogic->getConfiguration('id')]['code'];
      if (is_object($gateway)) {
        $gateway->callGateway($this->getConfiguration('value'),$eqLogic->getConfiguration('id_serrure'),$code);
      } else {
        log::add('ring', 'debug', 'Gateway non existante : ' . $gatewayid);
      }
      log::add('ring', 'debug', 'Commande : ' . $this->getConfiguration('value') . ' ' . $eqLogic->getConfiguration('id_serrure') . ' ' . $code);
      ring::updateUser();
      break;
      case 'gateway' :
      $eqLogic = $this->getEqLogic();
      ring::updateUser();
      ring::checkShare();
      $eqLogic->scanLockers();
      break;
      case 'digicode' :
      $eqLogic = $this->getEqLogic();
      $locker = ring::byLogicalId($eqLogic->getConfiguration('id_serrure'), 'ring');
      $locker->editShare($eqLogic->getConfiguration('id_share'), $eqLogic->getConfiguration('id') . '-' . $eqLogic->getConfiguration('code'), $this->getConfiguration('value'), false);
      ring::updateUser();
      ring::checkShare();
      break;
      default :
      $eqLogic = $this->getEqLogic();
      if ($this->getConfiguration('category') == 'phone') {
        $key = config::byKey('shares_phone','ring');
        $phone = true;
      } else {
        $key = config::byKey('shares_accessoire','ring');
        $phone = false;
      }
      $locker = ring::byLogicalId($this->getConfiguration('id'), 'ring');
      $id = $key[$eqLogic->getLogicalId()][$locker->getConfiguration('id')]['id'];
      log::add('ring', 'debug', 'Config : ' . $eqLogic->getLogicalId() . ' ' . $locker->getConfiguration('id') . ' ' . print_r(config::byKey('shares_accessoire','ring'),true));
      $locker->editShare($id, $eqLogic->getLogicalId(), $this->getConfiguration('value'), $phone);
      ring::updateUser();
      ring::checkShare();
      break;
    }
  }
}

?>
