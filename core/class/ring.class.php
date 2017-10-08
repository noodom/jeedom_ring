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
if (!class_exists('ringAPI')) { require_once dirname(__FILE__) . '/../../3rdparty/ringAPI.php'; }

class ring extends eqLogic {
  public static function deamon_info() {
    $return = array();
    $return['log'] = '';
    $return['state'] = 'nok';
    $cron = cron::byClassAndFunction('ring', 'daemon');
    if (is_object($cron) && $cron->running()) {
      $return['state'] = 'ok';
    }
    $return['launchable'] = 'ok';
    return $return;
  }

  public static function deamon_start($_debug = false) {
    self::deamon_stop();
    $deamon_info = self::deamon_info();
    if ($deamon_info['launchable'] != 'ok') {
      throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
    }
    $cron = cron::byClassAndFunction('ring', 'daemon');
    if (!is_object($cron)) {
      throw new Exception(__('Tache cron introuvable', __FILE__));
    }
    $cron->run();
  }

  public static function deamon_stop() {
    $cron = cron::byClassAndFunction('ring', 'daemon');
    if (!is_object($cron)) {
      throw new Exception(__('Tache cron introuvable', __FILE__));
    }
    $cron->halt();
  }

  public static function daemon() {
    $username = config::byKey('username', 'ring');
    $password = config::byKey('password', 'ring');
    $bell = new RingAPI();
    log::add('ring', 'debug', 'Connecting : ' . $username . ' ' . $password);
    $bell->authenticate($username, $password);
    log::add('ring', 'debug', 'Devices : ' . print_r($bell->devices(), true));
    foreach ($bell->devices->doorbots as $device) {
      ring::createStuff($device);
    }
    foreach ($bell->devices->authorized_doorbots as $device) {
      ring::createStuff($device);
    }
    while(1) {
      $states = $bell->poll();
      if ($states) {
        foreach($states as $state) {
          $ring = self::byLogicalId($state['doorbot_id'], 'ring');
          if ($state['is_ding']) {
            log::add('ring', 'debug', 'Ring : ' . print_r($state, true));
            $ring->checkAndUpdateCmd('ring',1);
          }

          if ($state['is_motion']) {
            log::add('ring', 'debug', 'Motion : ' . print_r($state, true));
            $ring->checkAndUpdateCmd('motion',1);
          }
        }
      }
      sleep(5);
    }
  }

  public static function createStuff($_stuff) {
      log::add('ring', 'debug', 'Stuff : ' . $device->id);
      $ring = self::byLogicalId($device->id, 'ring');
      if (!is_object($ring)) {
        $ring = new ring();
        $ring->setEqType_name('ring');
        $ring->setLogicalId($device->id);
        $ring->setName('Ring - '. $device->description);
        $ring->save();
      }
      $ring->batteryStatus($device->battery_life);
      $ring->setConfiguration('description',$device->description);
      $ring->setConfiguration('battery_life',$device->battery_life);
      $ring->setConfiguration('device_id',$device->device_id);
      $ring->setConfiguration('firmware_version',$device->firmware_version);
      $ring->setConfiguration('kind',$device->kind);
      $ring->save();
      $cmdlogic = ringCmd::byEqLogicIdAndLogicalId($ring->getId(),'ring');
      if (!is_object($cmdlogic)) {
        $cmdlogic = new ringCmd();
        $cmdlogic->setEqLogic_id($ring->getId());
        $cmdlogic->setEqType('ring');
        $cmdlogic->setType('info');
        $cmdlogic->setName('Sonnette');
        $cmdlogic->setLogicalId('ring');
        $cmdlogic->setSubType('binary');
        $cmdlogic->setDisplay('generic_type','PRESENCE');
        $cmdlogic->setConfiguration('returnStateValue',0);
        $cmdlogic->setConfiguration('returnStateTime',1);
        $cmdlogic->setConfiguration('repeatEventManagement','always');
        $cmdlogic->setTemplate("mobile",'alert');
        $cmdlogic->setTemplate("dashboard",'alert' );
        $cmdlogic->save();
      }
      $cmdlogic = ringCmd::byEqLogicIdAndLogicalId($ring->getId(),'motion');
      if (!is_object($cmdlogic)) {
        $cmdlogic = new ringCmd();
        $cmdlogic->setEqLogic_id($ring->getId());
        $cmdlogic->setEqType('ring');
        $cmdlogic->setType('info');
        $cmdlogic->setName('Mouvement');
        $cmdlogic->setLogicalId('motion');
        $cmdlogic->setSubType('binary');
        $cmdlogic->setDisplay('generic_type','PRESENCE');
        $cmdlogic->setConfiguration('returnStateValue',0);
        $cmdlogic->setConfiguration('returnStateTime',1);
        $cmdlogic->setConfiguration('repeatEventManagement','always');
        $cmdlogic->setTemplate("mobile",'alert');
        $cmdlogic->setTemplate("dashboard",'alert' );
        $cmdlogic->save();
      }
      $cmdlogic = ringCmd::byEqLogicIdAndLogicalId($ring->getId(),'battery');
      if (!is_object($cmdlogic)) {
        $cmdlogic = new ringCmd();
        $cmdlogic->setEqLogic_id($ring->getId());
        $cmdlogic->setEqType('ring');
        $cmdlogic->setType('info');
        $cmdlogic->setName('Batterie');
        $cmdlogic->setLogicalId('battery');
        $cmdlogic->setSubType('numeric');
        $cmdlogic->setIsVisible('0');
        $cmdlogic->save();
      }
      $ring->checkAndUpdateCmd('battery',$device->battery_life);
    }
}

class ringCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getType() == 'info') {
      return;
    }

  }
}

?>
