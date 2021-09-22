<?php

use CFPropertyList\CFPropertyList;

class Usb_model extends \Model {

	function __construct($serial='')
	{
		parent::__construct('id', 'usb'); //primary key, tablename
		$this->rs['id'] = '';
		$this->rs['serial_number'] = $serial;
		$this->rs['name'] = '';
		$this->rs['type'] = ''; // Mouse, Trackpad, Hub, etc.
		$this->rs['manufacturer'] = '';
		$this->rs['vendor_id'] = '';
		$this->rs['device_speed'] = ''; // USB Speed
		$this->rs['internal'] = 0; // True or False
		$this->rs['media'] = 0; // True or False
		$this->rs['bus_power'] = 0;
		$this->rs['bus_power_used'] = 0;
		$this->rs['extra_current_used'] = 0;
		$this->rs['usb_serial_number'] = ''; // USB device serial number
		$this->rs['printer_id'] = ''; // 1284 Device ID information, only used by printers

		// Add local config
		configAppendFile(__DIR__ . '/config.php');

		$this->serial_number = $serial;
	}

	// ------------------------------------------------------------------------
	/**
	 * Process data sent by postflight
	 *
	 * @param string data
	 * @author miqviq, revamped by tuxudo
	 **/
	function process($plist)
	{
        // Check if we have data
		if ( ! $plist){
			throw new Exception("Error Processing Request: No property list found", 1);
		}

		// Delete previous set        
		$this->deleteWhere('serial_number=?', $this->serial_number);

		$parser = new CFPropertyList();
		$parser->parse($plist, CFPropertyList::FORMAT_XML);
		$myList = $parser->toArray();

        // Process each device
		foreach ($myList as $device) {
			// Check if we have a name
			if( ! array_key_exists("name", $device)){
				continue;
			}

			// Skip Bus types USB31Bus, USB11Bus, etc.
			if(preg_match('/^USB(\d+)?Bus$/', $device['name']))
			{
				continue;
			}

			// Check for USB bus devices and simulated USB devices to exclude
			$excludeusb = array("OHCI Root Hub Simulation","UHCI Root Hub Simulation","EHCI Root Hub Simulation","RHCI Root Hub Simulation","XHCI Root Hub Simulation","XHCI Root Hub SS Simulation","XHCI Root Hub USB 2.0 Simulation");
			if (in_array($device['name'], $excludeusb)) {
				continue;
			}

			// Skip internal devices if value is TRUE
			if (!conf('usb_internal')) {
    			if ($device['internal']){
					continue;
				}
			}

			// Adjust names
			$device['name'] = str_replace(array('bluetooth_device','hub_device','composite_device'), array('Bluetooth USB Host Controller','USB Hub','Composite Device'), $device['name']);

			// Adjust USB speeds
			if (array_key_exists("device_speed",$device)) {
				$device['device_speed'] = str_replace(array('low_speed','full_speed','high_speed','super_speed'), array('USB 1.0','USB 1.1','USB 2.0','USB 3.x'), $device['device_speed']);
			} else {
				$device['device_speed'] = 'USB 1.1';
			}

			// Make sure manufacturer is set
			$device['manufacturer'] = isset($device['manufacturer']) ? $device['manufacturer'] : '';

			// Make sure printer_id is set
			$device['printer_id'] = isset($device['printer_id']) ? $device['printer_id'] : '';

			// Map name to device type
			$device_types = array(
				'Camera' => 'isight|camera|video|facetime|webcam',
				'USB Hub' => 'hub',
				'Keyboard' => 'keyboard|keykoard|usb kb',
				'IR Receiver' => 'ir receiver',
				'Bluetooth Controller' => 'bluetooth',
				'iPhone' => 'iphone',
				'iPad' => 'ipad',
				'iPod' => 'ipod',
				'Mouse' => 'mouse|ps2 orbit|trackpad',
				'Mass Storage' => 'card reader|os x install disk|apple usb superdrive|ultra fast media reader|usb to serial-ata bridge',
				'Audio Device' => 'audio|sound|headset|microphone|akm',
				'Display' => 'displaylink|display|monitor|touchscreen',
				'Composite Device' => 'composite device',
				'Network' => 'network|ethernet|modem|bcm',
				'UPS' => 'ups',
				'iBridge' => 'ibridge',
				'Scanner' => 'scanner',
				'Wacom Tablet' => 'wacom|ptz-|intuos|ctl-',
				'Interactive Board' => 'smartboard|activboard'
			);

			// Set device type to be default of unknown
			$device['type'] = "unknown";

			// Set new device type based on device name
			$device_name = strtolower($device['name']);
			foreach($device_types as $type => $pattern){
				if (preg_match('/'.$pattern.'/', $device_name)){
					$device['type'] = $type;
					break;
				}
			}

			// Set device types based on other criteria
			if (stripos($device['manufacturer'], 'DisplayLink') !== false) {
				$device['type'] = 'Display'; // Set by manufacturer instead of name
			} else if ($device['printer_id'] !== '') {
				$device['type'] = 'Printer'; // Set type to printer if printer_id field is not blank
			}

			// Check for Mass Storage
			if ($device['media'] == 1 ) {
				$device['type'] = 'Mass Storage';
			}

			// Override Internal T/F based on name
			if (stripos($device['name'], 'Internal') !== false) {
				$device['internal'] = 1;
			}

			// Adjust Apple vendor ID
			if (array_key_exists('vendor_id',$device)) {
                if ($device['vendor_id'] == 'apple_vendor_id') {
                    $device['vendor_id'] = '0x05ac (Apple, Inc.)';
                }

                // Set manufacturer from vendor ID if it's blank
                if ($device['manufacturer'] == '' && $device['vendor_id'] != '') {
                    preg_match('/\((.*?)\)/s', $device['vendor_id'], $manufactureroutput);
                    $device['manufacturer'] = $manufactureroutput[1];
                }
            }

            // Process each key
			foreach ($this->rs as $key => $value) {
				$this->rs[$key] = $value;
				if(array_key_exists($key, $device))
				{
					$this->rs[$key] = $device[$key];
				}
			}

			// Save device
			$this->id = '';
			$this->save();
		}
	}
}
