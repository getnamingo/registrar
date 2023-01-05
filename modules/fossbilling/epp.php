<?php
/**
 * Indera EPP registrar module for FOSSBilling (https://fossbilling.org/)
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 * Based on Generic EPP with DNSsec Registrar Module for WHMCS written in 2019 by Lilian Rudenco (info@xpanel.com)
 * Work of Lilian Rudenco is under http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *
 * @license MIT
 */
class Registrar_Adapter_EPP extends Registrar_AdapterAbstract
{
    public $config = array();

    public function __construct($options)
    {
        if(isset($options['username'])) {
            $this->config['username'] = $options['username'];
        }
        if(isset($options['password'])) {
            $this->config['password'] = $options['password'];
        }
        if(isset($options['host'])) {
            $this->config['host'] = $options['host'];
        }
        if(isset($options['port'])) {
            $this->config['port'] = $options['port'];
        }
        if(isset($options['registrarprefix'])) {
            $this->config['registrarprefix'] = $options['registrarprefix'];
        }
    }

    public function getTlds()
    {
        return array();
    }
    
    public static function getConfig()
    {
        return array(
            'label' => 'An EPP registry module allows registrars to manage and register domain names using the Extensible Provisioning Protocol (EPP). All details below are typically provided by the domain registry and are used to authenticate your account when connecting to the EPP server.',
            'form'  => array(
                'username' => array('text', array(
                    'label' => 'EPP Server Username',
                    'required' => true,
                ),
                ),
                'password' => array('password', array(
                    'label' => 'EPP Server Password',
                    'required' => true,
                ),
                ),
                'host' => array('text', array(
                    'label' => 'EPP Server Host',
                    'required' => true,
                ),
                ),
                'port' => array('text', array(
                    'label' => 'EPP Server Port',
                    'required' => true,
                ),
                ),
                'registrarprefix' => array('text', array(
                    'label' => 'Registrar Prefix',
                    'required' => true,
                ),
                ),
            ),
        );
    }
    
    public function isDomaincanBeTransferred(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Checking if domain can be transferred: ' . $domain->getName());
        return true;
    }

    public function isDomainAvailable(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Checking domain availability: ' . $domain->getName());
		$s	= $this->connect();
		$this->login();
		$from = $to = array();
		$from[] = '/{{ name }}/';
		$to[] = htmlspecialchars($domain->getName());
		$from[] = '/{{ clTRID }}/';
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-check-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
		<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
		  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
		  <command>
			<check>
			  <domain:check
				xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
				xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
				<domain:name>{{ name }}</domain:name>
			  </domain:check>
			</check>
			<clTRID>{{ clTRID }}</clTRID>
		  </command>
		</epp>');

		$r = $this->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->chkData;
		$reason = (string)$r->cd[0]->reason;

		if ($reason)
		{
			return false;
		} else {
			return true;
		}
		if (!empty($s))
		{
			$this->logout();
		}

        return true;
    }

    public function modifyNs(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Modifying nameservers: ' . $domain->getName());
        $this->getLog()->debug('Ns1: ' . $domain->getNs1());
        $this->getLog()->debug('Ns2: ' . $domain->getNs2());
        $this->getLog()->debug('Ns3: ' . $domain->getNs3());
        $this->getLog()->debug('Ns4: ' . $domain->getNs4());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<info>
		  <domain:info
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name hosts="all">{{ name }}</domain:name>
		  </domain:info>
		</info>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
			$add = $rem = array();
			$i = 0;
			foreach($r->ns->hostObj as $ns) {
				$i++;
				$ns = (string)$ns;
				if (!$ns) {
					continue;
				}

				$rem["ns{$i}"] = $ns;
			}

			foreach (range(1, 4) as $i) {
			  $k = "getNs$i";
			  $v = $domain->{$k}();
			  if (!$v) {
				continue;
			  }

			  if ($k0 = array_search($v, $rem)) {
				unset($rem[$k0]);
			  } else {
				$add["ns$i"] = $v;
			  }
			}

			if (!empty($add) || !empty($rem)) {
				$from = $to = array();
				$text = '';
				foreach($add as $k => $v) {
					$text.= '<domain:hostObj>' . $v . '</domain:hostObj>' . "\n";
				}

				$from[] = '/{{ add }}/';
				$to[] = (empty($text) ? '' : "<domain:add><domain:ns>\n{$text}</domain:ns></domain:add>\n");
				$text = '';
				foreach($rem as $k => $v) {
					$text.= '<domain:hostObj>' . $v . '</domain:hostObj>' . "\n";
				}

				$from[] = '/{{ rem }}/';
				$to[] = (empty($text) ? '' : "<domain:rem><domain:ns>\n{$text}</domain:ns></domain:rem>\n");
				$from[] = '/{{ name }}/';
				$to[] = htmlspecialchars($domain->getName());
				$from[] = '/{{ clTRID }}/';
				$clTRID = str_replace('.', '', round(microtime(1), 3));
				$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-update-' . $clTRID);
				$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<update>
		  <domain:update
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name>{{ name }}</domain:name>
		{{ add }}
		{{ rem }}
		  </domain:update>
		</update>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
				$r = $this->write($xml, __FUNCTION__);
			}
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

    public function transferDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Transfering domain: ' . $domain->getName());
        $this->getLog()->debug('Epp code: ' . $domain->getEpp());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ authInfo_pw }}/';
			$to[] = htmlspecialchars($domain->getEpp());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-transfer-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<transfer op="request">
	  <domain:transfer
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
		<domain:name>{{ name }}</domain:name>
		<domain:period unit="y">1</domain:period>
		<domain:authInfo>
		  <domain:pw>{{ authInfo_pw }}</domain:pw>
		</domain:authInfo>
	  </domain:transfer>
	</transfer>
	<clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->trnData;
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

    public function getDomainDetails(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Getting whois: ' . $domain->getName());

        if(!$domain->getRegistrationTime()) {
            $domain->setRegistrationTime(time());
        }
        if(!$domain->getExpirationTime()) {
            $years = $domain->getRegistrationPeriod();
            $domain->setExpirationTime(strtotime("+$years year"));
        }
        return $domain;
    }

    public function deleteDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Removing domain: ' . $domain->getName());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-delete-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<delete>
	  <domain:delete
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
		<domain:name>{{ name }}</domain:name>
	  </domain:delete>
	</delete>
	<clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
			$r = $this->write($xml, __FUNCTION__);
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

    public function registerDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Registering domain: ' . $domain->getName(). ' for '.$domain->getRegistrationPeriod(). ' years');
		$client = $domain->getContactRegistrar();

		$return = array();
		try {
			$s = $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-check-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<check>
		  <domain:check
			xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
			xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name>{{ name }}</domain:name>
		  </domain:check>
		</check>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->chkData;
			$reason = (string)$r->cd[0]->reason;
			if (!$reason) {
				$reason = 'Domain is not available';
			}

			if (0 == (int)$r->cd[0]->name->attributes()->avail) {
				throw new exception($r->cd[0]->name . ' ' . $reason);
			}
			
			// contact:create
			$from = $to = array();
			$from[] = '/{{ id }}/';
			$c_id = strtoupper($this->generateRandomString());
			$to[] = $c_id;
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($client->getFirstName() . ' ' . $client->getLastName());
			$from[] = '/{{ org }}/';
			$to[] = htmlspecialchars($client->getCompany());
			$from[] = '/{{ street1 }}/';
			$to[] = htmlspecialchars($client->getAddress1());
			$from[] = '/{{ city }}/';
			$to[] = htmlspecialchars($client->getCity());
			$from[] = '/{{ state }}/';
			$to[] = htmlspecialchars($client->getState());
			$from[] = '/{{ postcode }}/';
			$to[] = htmlspecialchars($client->getZip());
			$from[] = '/{{ country }}/';
			$to[] = htmlspecialchars($client->getCountry());
			$from[] = '/{{ phonenumber }}/';
			$to[] = htmlspecialchars('+'.$client->getTelCc().'.'.$client->getTel());
			$from[] = '/{{ email }}/';
			$to[] = htmlspecialchars($client->getEmail());
			$from[] = '/{{ authInfo }}/';
			$to[] = htmlspecialchars($this->generateObjectPW());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-contact-create-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<create>
		  <contact:create
		   xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
			<contact:id>{{ id }}</contact:id>
			<contact:postalInfo type="int">
			  <contact:name>{{ name }}</contact:name>
			  <contact:org>{{ org }}</contact:org>
			  <contact:addr>
				<contact:street>{{ street1 }}</contact:street>
				<contact:street></contact:street>
				<contact:street></contact:street>
				<contact:city>{{ city }}</contact:city>
				<contact:sp>{{ state }}</contact:sp>
				<contact:pc>{{ postcode }}</contact:pc>
				<contact:cc>{{ country }}</contact:cc>
			  </contact:addr>
			</contact:postalInfo>
			<contact:voice>{{ phonenumber }}</contact:voice>
			<contact:fax></contact:fax>
			<contact:email>{{ email }}</contact:email>
			<contact:authInfo>
			  <contact:pw>{{ authInfo }}</contact:pw>
			</contact:authInfo>
		  </contact:create>
		</create>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:contact-1.0')->creData;
			$contacts = $r->id;

			//host create
			foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $ns) {
				if ($domain->{'get' . ucfirst($ns)}()) {
					$from = $to = array();
					$from[] = '/{{ name }}/';
					$to[] = $domain->{'get' . ucfirst($ns)}();
					$from[] = '/{{ clTRID }}/';
					$clTRID = str_replace('.', '', round(microtime(1), 3));
					$to[] = htmlspecialchars($this->config['registrarprefix'] . '-host-check-' . $clTRID);
					$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
					<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
					  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
					  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
					  <command>
						<check>
						  <host:check
							xmlns:host="urn:ietf:params:xml:ns:host-1.0"
							xsi:schemaLocation="urn:ietf:params:xml:ns:host-1.0 host-1.0.xsd">
							<host:name>{{ name }}</host:name>
						  </host:check>
						</check>
						<clTRID>{{ clTRID }}</clTRID>
					  </command>
					</epp>');
					$r = $this->write($xml, __FUNCTION__);
					$r = $r->response->resData->children('urn:ietf:params:xml:ns:host-1.0')->chkData;

					if (0 == (int)$r->cd[0]->name->attributes()->avail) {
						continue;
					}

					$from = $to = array();
					$from[] = '/{{ name }}/';
					$to[] = $domain->{'get' . ucfirst($ns)}();
					$from[] = '/{{ clTRID }}/';
					$clTRID = str_replace('.', '', round(microtime(1), 3));
					$to[] = htmlspecialchars($this->config['registrarprefix'] . '-host-create-' . $clTRID);
					$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
					<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
					  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
					  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
					  <command>
						<create>
						  <host:create
						   xmlns:host="urn:ietf:params:xml:ns:host-1.0">
							<host:name>{{ name }}</host:name>
							<host:addr ip="v4">5.6.7.8</host:addr>
						  </host:create>
						</create>
						<clTRID>{{ clTRID }}</clTRID>
					  </command>
					</epp>');
					$r = $this->write($xml, __FUNCTION__);
				}
			}

			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ period }}/';
			$to[] = htmlspecialchars($domain->getRegistrationPeriod());
			if($domain->getNs1()) {
			$from[] = '/{{ ns1 }}/';
			$to[] = htmlspecialchars($domain->getNs1());
			} else {
			$from[] = '/{{ ns1 }}/';
			$to[] = '';
			}
			if($domain->getNs2()) {
			$from[] = '/{{ ns2 }}/';
			$to[] = htmlspecialchars($domain->getNs2());
			} else {
			$from[] = '/{{ ns2 }}/';
			$to[] = '';
			}
			if($domain->getNs3()) {
			$from[] = '/{{ ns3 }}/';
			$to[] = htmlspecialchars($domain->getNs3());
			} else {
			$from[] = '/{{ ns3 }}/';
			$to[] = '';
			}
			if($domain->getNs4()) {
			$from[] = '/{{ ns4 }}/';
			$to[] = htmlspecialchars($domain->getNs4());
			} else {
			$from[] = '/{{ ns4 }}/';
			$to[] = '';
			}
			$from[] = '/{{ cID_1 }}/';
			$to[] = htmlspecialchars($contacts);
			$from[] = '/{{ cID_2 }}/';
			$to[] = htmlspecialchars($contacts);
			$from[] = '/{{ cID_3 }}/';
			$to[] = htmlspecialchars($contacts);
			$from[] = '/{{ cID_4 }}/';
			$to[] = htmlspecialchars($contacts);
			$from[] = '/{{ authInfo }}/';
			$to[] = htmlspecialchars($this->generateObjectPW());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-create-' . $clTRID);
			$from[] = "/<\w+:\w+>\s*<\/\w+:\w+>\s+/ims";
			$to[] = '';
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
			<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
			  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
			  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
			  <command>
				<create>
				  <domain:create
				   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
					<domain:name>{{ name }}</domain:name>
					<domain:period unit="y">{{ period }}</domain:period>
					<domain:ns>
					  <domain:hostObj>{{ ns1 }}</domain:hostObj>
					  <domain:hostObj>{{ ns2 }}</domain:hostObj>
					  <domain:hostObj>{{ ns3 }}</domain:hostObj>
					  <domain:hostObj>{{ ns4 }}</domain:hostObj>
					</domain:ns>
					<domain:registrant>{{ cID_1 }}</domain:registrant>
					<domain:contact type="admin">{{ cID_2 }}</domain:contact>
					<domain:contact type="tech">{{ cID_3 }}</domain:contact>
					<domain:contact type="billing">{{ cID_4 }}</domain:contact>
					<domain:authInfo>
					  <domain:pw>{{ authInfo }}</domain:pw>
					</domain:authInfo>
				  </domain:create>
				</create>
				<clTRID>{{ clTRID }}</clTRID>
			  </command>
			</epp>');
			$r = $this->write($xml, __FUNCTION__);
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

    public function renewDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Renewing domain: ' . $domain->getName());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<info>
		  <domain:info
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name hosts="all">{{ name }}</domain:name>
		  </domain:info>
		</info>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
			$expDate = (string)$r->exDate;
			$expDate = preg_replace("/^(\d+\-\d+\-\d+)\D.*$/", "$1", $expDate);
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ expDate }}/';
			$to[] = htmlspecialchars($expDate);
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-renew-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<renew>
		  <domain:renew
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
			<domain:name>{{ name }}</domain:name>
			<domain:curExpDate>{{ expDate }}</domain:curExpDate>
			<domain:period unit="y">1</domain:period>
		  </domain:renew>
		</renew>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

    public function modifyContact(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Updating contact info: ' . $domain->getName());
		$client = $domain->getContactRegistrar();
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<info>
		  <domain:info
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name hosts="all">{{ name }}</domain:name>
		  </domain:info>
		</info>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
			$registrant = (string)$r->registrant;
			$from = $to = array();
			$from[] = '/{{ id }}/';
			$to[] = $registrant;
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($client->getFirstName() . ' ' . $client->getLastName());
			$from[] = '/{{ org }}/';
			$to[] = htmlspecialchars($client->getCompany());
			$from[] = '/{{ street1 }}/';
			$to[] = htmlspecialchars($client->getAddress1());
			$from[] = '/{{ street2 }}/';
			$to[] = htmlspecialchars($client->getAddress2());
			$from[] = '/{{ city }}/';
			$to[] = htmlspecialchars($client->getCity());
			$from[] = '/{{ state }}/';
			$to[] = htmlspecialchars($client->getState());
			$from[] = '/{{ postcode }}/';
			$to[] = htmlspecialchars($client->getZip());
			$from[] = '/{{ country }}/';
			$to[] = htmlspecialchars($client->getCountry());
			$from[] = '/{{ phonenumber }}/';
			$to[] = htmlspecialchars('+'.$client->getTelCc().'.'.$client->getTel());
			$from[] = '/{{ email }}/';
			$to[] = htmlspecialchars($client->getEmail());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-contact-update-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
  <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
    <command>
      <update>
        <contact:update
         xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
          <contact:id>{{ id }}</contact:id>
          <contact:chg>
            <contact:postalInfo type="int">
			  <contact:name>{{ name }}</contact:name>
              <contact:org>{{ org }}</contact:org>
              <contact:addr>
                <contact:street>{{ street1 }}</contact:street>
                <contact:street>{{ street2 }}</contact:street>
                <contact:street></contact:street>
                <contact:city>{{ city }}</contact:city>
                <contact:sp>{{ state }}</contact:sp>
                <contact:pc>{{ postcode }}</contact:pc>
                <contact:cc>{{ country }}</contact:cc>
              </contact:addr>
            </contact:postalInfo>
            <contact:voice>{{ phonenumber }}</contact:voice>
            <contact:fax></contact:fax>
          </contact:chg>
        </contact:update>
      </update>
      <clTRID>{{ clTRID }}</clTRID>
    </command>
</epp>');
			$r = $this->write($xml, __FUNCTION__);
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }
    
    public function enablePrivacyProtection(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Enabling Privacy protection: ' . $domain->getName());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<info>
		  <domain:info
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name hosts="all">{{ name }}</domain:name>
		  </domain:info>
		</info>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
			$dcontact = array();
			$dcontact['registrant'] = (string)$r->registrant;
			foreach($r->contact as $e) {
				$type = (string)$e->attributes()->type;
				$dcontact[$type] = (string)$e;
			}

			$contact = array();
			foreach($dcontact as $id) {
				if (isset($contact[$id])) {
					continue;
				}
				$from = $to = array();
				$from[] = '/{{ id }}/';
				$to[] = htmlspecialchars($id);
				$from[] = '/{{ flag }}/';
				$to[] = 1;
				$from[] = '/{{ clTRID }}/';
				$clTRID = str_replace('.', '', round(microtime(1) , 3));
				$to[] = htmlspecialchars($this->config['registrarprefix'] . '-contact-update-' . $clTRID);
				$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
		 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<update>
		  <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd">
			<contact:id>{{ id }}</contact:id>
			<contact:chg>
			  <contact:disclose flag="{{ flag }}">
				<contact:name type="int"/>
				<contact:addr type="int"/>
				<contact:org type="int"/>
				<contact:voice/>
				<contact:fax/>
				<contact:email/>
			  </contact:disclose>
			</contact:chg>
		  </contact:update>
		</update>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
				$r = $this->write($xml, __FUNCTION__);
			}
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }
    
    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Disabling Privacy protection: ' . $domain->getName());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<info>
		  <domain:info
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name hosts="all">{{ name }}</domain:name>
		  </domain:info>
		</info>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
			$dcontact = array();
			$dcontact['registrant'] = (string)$r->registrant;
			foreach($r->contact as $e) {
				$type = (string)$e->attributes()->type;
				$dcontact[$type] = (string)$e;
			}

			$contact = array();
			foreach($dcontact as $id) {
				if (isset($contact[$id])) {
					continue;
				}
				$from = $to = array();
				$from[] = '/{{ id }}/';
				$to[] = htmlspecialchars($id);
				$from[] = '/{{ flag }}/';
				$to[] = 0;
				$from[] = '/{{ clTRID }}/';
				$clTRID = str_replace('.', '', round(microtime(1) , 3));
				$to[] = htmlspecialchars($this->config['registrarprefix'] . '-contact-update-' . $clTRID);
				$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
		 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<update>
		  <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd">
			<contact:id>{{ id }}</contact:id>
			<contact:chg>
			  <contact:disclose flag="{{ flag }}">
				<contact:name type="int"/>
				<contact:addr type="int"/>
				<contact:org type="int"/>
				<contact:voice/>
				<contact:fax/>
				<contact:email/>
			  </contact:disclose>
			</contact:chg>
		  </contact:update>
		</update>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
				$r = $this->write($xml, __FUNCTION__);
			}
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

    public function getEpp(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Retrieving domain transfer code: ' . $domain->getName());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
			<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
			  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
			  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
			  <command>
				<info>
				  <domain:info
				   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
				   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
					<domain:name hosts="all">{{ name }}</domain:name>
				  </domain:info>
				</info>
				<clTRID>{{ clTRID }}</clTRID>
			  </command>
			</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
			$eppcode = (string)$r->authInfo->pw;

			if (!empty($s)) {
					$this->logout();
				}
			return $eppcode;
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

    public function lock(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Locking domain: ' . $domain->getName());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<info>
		  <domain:info
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name hosts="all">{{ name }}</domain:name>
		  </domain:info>
		</info>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
			$status = array();
			foreach($r->status as $e) {
				$st = (string)$e->attributes()->s;
				if (!preg_match("/^client.+Prohibited$/i", $st)) {
					continue;
				}

				$status[$st] = true;
			}

			$add = array();
			foreach(array(
				'clientUpdateProhibited',
				'clientDeleteProhibited',
				'clientTransferProhibited'
			) as $st) {
				if (!isset($status[$st])) {
					$add[] = $st;
				}
			}

			if (!empty($add)) {
				$text = '';
				foreach($add as $st) {
					$text.= '<domain:status s="' . $st . '" lang="en"></domain:status>' . "\n";
				}
				$from[] = '/{{ add }}/';
				$to[] = (empty($text) ? '' : "<domain:add>\n{$text}</domain:add>\n");
				$from[] = '/{{ name }}/';
				$to[] = htmlspecialchars($domain->getName());
				$from[] = '/{{ clTRID }}/';
				$clTRID = str_replace('.', '', round(microtime(1), 3));
				$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-update-' . $clTRID);
				$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<update>
		  <domain:update
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name>{{ name }}</domain:name>
			{{ add }}
		  </domain:update>
		</update>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
				$r = $this->write($xml, __FUNCTION__);
			}
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

    public function unlock(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Unlocking: ' . $domain->getName());
		$return = array();
		try {
			$s	= $this->connect();
			$this->login();
			$from = $to = array();
			$from[] = '/{{ name }}/';
			$to[] = htmlspecialchars($domain->getName());
			$from[] = '/{{ clTRID }}/';
			$clTRID = str_replace('.', '', round(microtime(1), 3));
			$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<info>
		  <domain:info
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name hosts="all">{{ name }}</domain:name>
		  </domain:info>
		</info>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
			$r = $this->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
			$status = array();
			foreach($r->status as $e) {
				$st = (string)$e->attributes()->s;
				if (!preg_match("/^client.+Prohibited$/i", $st)) {
					continue;
				}

				$status[$st] = true;
			}

			$rem = array();
			foreach(array(
				'clientUpdateProhibited',
				'clientDeleteProhibited',
				'clientTransferProhibited'
			) as $st) {
				if (isset($status[$st])) {
					$rem[] = $st;
				}
			}

			if (!empty($rem)) {
				$text = '';
				foreach($rem as $st) {
					$text.= '<domain:status s="' . $st . '" lang="en"></domain:status>' . "\n";
				}
				$from[] = '/{{ rem }}/';
				$to[] = (empty($text) ? '' : "<domain:rem>\n{$text}</domain:rem>\n");
				$from[] = '/{{ name }}/';
				$to[] = htmlspecialchars($domain->getName());
				$from[] = '/{{ clTRID }}/';
				$clTRID = str_replace('.', '', round(microtime(1), 3));
				$to[] = htmlspecialchars($this->config['registrarprefix'] . '-domain-update-' . $clTRID);
				$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<update>
		  <domain:update
		   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
			<domain:name>{{ name }}</domain:name>
			{{ rem }}
		  </domain:update>
		</update>
		<clTRID>{{ clTRID }}</clTRID>
	  </command>
	</epp>');
				$r = $this->write($xml, __FUNCTION__);
			}
		}

		catch(exception $e) {
			$return = array(
				'error' => $e->getMessage()
			);
		}

		if (!empty($s)) {
			$this->logout();
		}

		return $return;
    }

	public function connect()
	{
		$host = $this->config['host'];
		$port = $this->config['port'];
		
		$opts = array(
			'ssl' => array(
				'verify_peer' => false,
				'verify_peer_name' => false,
				'verify_host' => false,
				'allow_self_signed' => true,
				'local_cert' => 'cert.pem',
				'local_pk' => 'key.pem'
			)
		);
		$context = stream_context_create($opts);
		$this->socket = stream_socket_client("tlsv1.3://{$host}:{$port}", $errno, $errmsg, $timeout, STREAM_CLIENT_CONNECT, $context);

		if (!$this->socket) {
			throw new exception("Cannot connect to server '{$host}': {$errmsg}");
		}

		return $this->read();
	}

	public function login()
	{
		$from = $to = array();
		$from[] = '/{{ clID }}/';
		$to[] = htmlspecialchars($this->config['username']);
		$from[] = '/{{ pw }}/';
		$to[] = $this->config['password'];
		$from[] = '/{{ clTRID }}/';
		$clTRID = str_replace('.', '', round(microtime(1), 3));
		$to[] = htmlspecialchars($this->config['registrarprefix'] . '-login-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<login>
	  <clID>{{ clID }}</clID>
	  <pw><![CDATA[{{ pw }}]]></pw>
	  <options>
		<version>1.0</version>
		<lang>en</lang>
	  </options>
	  <svcs>
		<objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
		<objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
		<objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
		<svcExtension>
		  <extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI>
		</svcExtension>
	  </svcs>
	</login>
	<clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
		$r = $this->write($xml, __FUNCTION__);
		$this->isLogined = true;
		return true;
	}

	public function logout()
	{
		if (!$this->isLogined) {
			return true;
		}

		$from = $to = array();
		$from[] = '/{{ clTRID }}/';
		$clTRID = str_replace('.', '', round(microtime(1), 3));
		$to[] = htmlspecialchars($this->config['registrarprefix'] . '-logout-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<logout/>
	<clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
		$r = $this->write($xml, __FUNCTION__);
		$this->isLogined = false;
		return true;
	}

	public function read()
	{
		if (feof($this->socket)) {
			throw new exception('Connection appears to have closed.');
		}

		$hdr = @fread($this->socket, 4);
		if (empty($hdr)) {
			throw new exception('Error reading from server.');
		}

		$unpacked = unpack('N', $hdr);
		$xml = fread($this->socket, ($unpacked[1] - 4));
		$xml = preg_replace('/></', ">\n<", $xml);
		return $xml;
	}

	public function write($xml, $action = 'Unknown')
	{
		@fwrite($this->socket, pack('N', (strlen($xml) + 4)) . $xml);
		$r = $this->read();
		$r = new SimpleXMLElement($r);
		if ($r->response->result->attributes()->code >= 2000) {
			throw new exception($r->response->result->msg);
		}
		return $r;
	}

	public function disconnect()
	{
		return @fclose($this->socket);
	}

	function generateObjectPW($objType = 'none')
	{
		$result = '';
		$uppercaseChars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
		$lowercaseChars = "abcdefghijklmnopqrstuvwxyz";
		$numbers = "1234567890";
		$specialSymbols = "!=+-";
		$minLength = 13;
		$maxLength = 13;
		$length = mt_rand($minLength, $maxLength);

		// Include at least one character from each set
		$result .= $uppercaseChars[mt_rand(0, strlen($uppercaseChars) - 1)];
		$result .= $lowercaseChars[mt_rand(0, strlen($lowercaseChars) - 1)];
		$result .= $numbers[mt_rand(0, strlen($numbers) - 1)];
		$result .= $specialSymbols[mt_rand(0, strlen($specialSymbols) - 1)];

		// Append random characters to reach the desired length
		while (strlen($result) < $length) {
			$chars = $uppercaseChars . $lowercaseChars . $numbers . $specialSymbols;
			$result .= $chars[mt_rand(0, strlen($chars) - 1)];
		}

		return 'aA1' . $result;
	}
	
	public function generateRandomString() 
	{
		$characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$randomString = '';
		for ($i = 0; $i < 12; $i++) {
			$randomString .= $characters[rand(0, strlen($characters) - 1)];
		}
		return $randomString;
	}
}
