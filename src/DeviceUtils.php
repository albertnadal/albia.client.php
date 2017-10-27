<?php

require_once 'protobuf_generated/DeviceEventMsg.php';

class DeviceTimestamp
{
    public $unixTimestamp;
    public $microseconds;

    public function __construct($unix_timestamp_ = null, $microseconds_ = null)
    {
        if ($unix_timestamp_ == null) {
            $this->unixTimestamp = time();
            $this->microseconds = 0;
        } else {
            $this->unixTimestamp = $unix_timestamp_;
            $this->microseconds = ($microseconds_ == null) ? 0 : $microseconds_;
        }
    }

    public static function UTCTimestampWithMicroseconds()
    {
        $deviceTimestamp = new DeviceTimestamp();
        list($usec, $sec) = explode(" ", microtime());
        $deviceTimestamp->unixTimestamp = (int)$sec;
        $deviceTimestamp->microseconds = floor((float)$usec * 1000000);
        return $deviceTimestamp;
    }
}

class DeviceEvent
{
    public $action;
    public $deviceId;
    public $targetDeviceId;
    public $date;
    public $data;

    public function __construct() {

    }

    public function initWithDeviceEventMsg(DeviceEventMsg $deviceEventMsg) {
      $this->action = $deviceEventMsg->getAction();
      $this->deviceId = $deviceEventMsg->getDeviceId();
      $this->targetDeviceId = $deviceEventMsg->getTargetDeviceId();
      $eventDate = $deviceEventMsg->getDate();
      $this->date = new DeviceTimestamp($eventDate->getSeconds(), $eventDate->getNanos()/1000);
      $this->data = $deviceEventMsg->getData();
    }
}
