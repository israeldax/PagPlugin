<?php

import('classes.plugins.PaymethodPlugin');

class PagseguroPlugin extends PaymethodPlugin{

	const URL_PAGSEGURO = 'https://pagseguro.uol.com.br';
	const URL_PAGSEGURO_SANDBOX = 'https://sandbox.pagseguro.uol.com.br';
	const URL_PAGSEGURO_WS = 'https://ws.pagseguro.uol.com.br';
	const URL_PAGSEGURO_WS_SANDBOX = 'https://ws.sandbox.pagseguro.uol.com.br';
	const PSSTATUS_AGURDANDO = 1;
	const PSSTATUS_EM_ANALISE = 2;
	const PSSTATUS_PAGO = 3;
	const PSSTATUS_DISPONIVEL = 4;
	const PSSTATUS_EM_DISPUTA = 5;
	const PSSTATUS_DEVOLVIDA = 6;
	const PSSTATUS_CANCELADA = 7;

	//token da conta do Pagseguro
	const EMAILPS = 'pagseguro email';
	const TOKEN = 'pagseguro token';

	function getName() {
		return 'Pagseguro';
	}

	function getDisplayName() {
		return __('plugins.paymethod.pagseguro.displayName');
	}

	function getDescription() {
		return __('plugins.paymethod.pagseguro.description');
	}

	function getSettingsFormFieldNames() {
		return array('testmode');
	}

	function getInstallSchemaFile() {
		return ($this->getPluginPath() . DIRECTORY_SEPARATOR . 'schema.xml');
	}

	function getInstallEmailTemplatesFile() {
		return ($this->getPluginPath() . '/emailTemplates.xml');
	}

	function getInstallEmailTemplateDataFile() {
		return ($this->getPluginPath() . '/locale/{$installedLocale}/emailTemplates.xml');
	}

	function displayPaymentSettingsForm(&$params, &$smarty) {
		$smarty->assign('isCurlInstalled', $this->isCurlInstalled());
		return parent::displayPaymentSettingsForm($params, $smarty);
	}

	function register($category, $path) {
		if (parent::register($category, $path)) {
			$this->addLocaleData();
			$this->import('PagseguroDAO');
			$pagseguroDao = new PagseguroDAO();
			DAORegistry::registerDAO('PagseguroDAO', $pagseguroDao);
			return true;
		}
		return false;
	}

	function isConfigured() {
		$schedConf =& Request::getSchedConf();
		if (!$schedConf) return false;

		// Make sure CURL support is included.
		if (!$this->isCurlInstalled()) return false;

		// Make sure that all settings form fields have been filled in
		foreach ($this->getSettingsFormFieldNames() as $settingName) {
			$setting = $this->getSetting($schedConf->getConferenceId(), $schedConf->getId(), $settingName);
			if (empty($setting)) return false;
		}
		return true;
	}

	function isCurlInstalled() {
		return (function_exists('curl_init'));
	}

	function displayPaymentForm($queuedPaymentId, &$queuedPayment) {
		if (!$this->isConfigured()) return false;

		// Setando valores da requisição
		$conference =& Request::getConference();
		$schedConf =& Request::getSchedConf();
		$user =& Request::getUser();
		$regData = date("Y-m-d H:i:s", time());
		$preco = number_format($queuedPayment->getAmount(), 2, '.', '');
		// Setando valores do xml
		$encoding = 'UTF-8';
		$testmode = $this->getSetting($schedConf->getConferenceId(), $schedConf->getId(), 'testmode');
		$urlPagseguro = ($testmode == true) ? self::URL_PAGSEGURO_WS_SANDBOX : self::URL_PAGSEGURO_WS;
		$urlPagseguro .= "/v2/checkout/?email=" . self::EMAILPS . "&token=" . self::TOKEN;
		$urlRedirecionamentoPS = Request::url(null, null, 'payment', 'landing');
		$urlNotification = ($testmode == true) ? "http://ocspp.ddns.net/ocs/index.php/".$conference->getPath()."/".$schedConf->getPath()."/payment/plugin/".$this->getName()."/refreshtxn"
				: Request::url(null, null, 'payment', 'plugin', array($this->getName(), 'refreshtxn'));
		$itemNo = "001";
		$item_nome = $schedConf->getFullTitle();
		$comprador_nome = $user->getFullName(false);
		$comprador_email = $user->getEmail();


		// Salva dados de requisição
		$pagseguroDao =& DAORegistry::getDAO('PagseguroDAO');
		$requisicaoID = $pagseguroDao->insertRequest($queuedPayment->getAssocId(), $regData, $schedConf->getId(), $preco, $user->getId(), $queuedPaymentId);

		$xml = "<?xml version=\"1.0\" encoding=\"$encoding\" standalone=\"yes\"?>
					<checkout>
						<redirectURL>$urlRedirecionamentoPS</redirectURL>
						<notificationURL>$urlNotification</notificationURL>
						<reference>$requisicaoID</reference>
						<currency>BRL</currency>
						<items>
							<item>
								<id>$itemNo</id>
								<description>$item_nome</description>
								<amount>$preco</amount>
								<quantity>1</quantity>
							</item>
						</items>
						<sender>
							<name>$comprador_nome</name>
							<email>$comprador_email</email>
						</sender>
					</checkout>";

		$curl = curl_init($urlPagseguro);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, Array("Content-Type: application/xml; charset=UTF-8"));
// 		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_POSTFIELDS, trim($xml));
		$xml = curl_exec($curl);
		curl_close($curl);

		// Error=1 Não autorizado.
		if ($xml == 'Unauthorized') {
			$templateMgr->assign('message', 'plugins.paymethod.pagseguro.error');
			$templateMgr->assign('backLinkLabel', 'common.back');
			$templateMgr->assign('backLink', Request::url(null, null, 'index'));
			return $templateMgr->display('common/message.tpl');
		}

		$xml = simplexml_load_string($xml);

		// Error=2 Erro genérico.
		if (count($xml->error) > 0) {
			$templateMgr->assign('message', 'plugins.paymethod.pagseguro.error');
			$templateMgr->assign('backLinkLabel', 'common.back');
			$templateMgr->assign('backLink', Request::url(null, null, 'index'));
			return $templateMgr->display('common/message.tpl');
		}

		// Mostra formulário de redirecionamento
		$templateMgr =& TemplateManager::getManager();
		$templateMgr->assign('preco', $preco);
		$templateMgr->assign('moeda', $queuedPayment->getCurrencyCode());
		$templateMgr->assign('item_nome',$item_nome);
		$templateMgr->assign('pagseguroFormUrl', ($testmode == true) ? self::URL_PAGSEGURO_SANDBOX.'/v2/checkout/payment.html?code='.$xml->code
				: self::URL_PAGSEGURO.'/v2/checkout/payment.html?code='.$xml->code);
		$templateMgr->display($this->getTemplatePath() . 'paymentForm.tpl');
	}


	/**
	 * Handle incoming requests/notifications
	 */
	function handle($args) {

		$templateMgr =& TemplateManager::getManager();
		$schedConf =& Request::getSchedConf();
		if (!$schedConf) return parent::handle($args);

		$notificationCode = Request::getUserVar('notificationCode');

		switch (array_shift($args)) {
			case 'refreshtxn':

				if (!empty($notificationCode)) {
					$transaction = null;

					// Setando  as variáveis do curl
					$testmode = $this->getSetting($schedConf->getConferenceId(), $schedConf->getId(), 'testmode');
					$url 	= (($testmode == true) ? self::URL_PAGSEGURO_WS_SANDBOX : self::URL_PAGSEGURO_WS);
					$url   .= "/v2/transactions/notifications/";
					$url   .= "$notificationCode" . '?email='.self::EMAILPS.'&token='.self::TOKEN;

					// Recupera transação do PS
					$curl = curl_init($url);
					curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					$transaction = curl_exec($curl);
					curl_close($curl);
					// Erro ao contatar o pagseguro
					if ($transaction == 'Unauthorized'){
						$dadosDoEmail = array(
								'schedConfName' => $schedConf->getFullTitle(),
								'motivo' => 'Erro ao contatar o pagseguro',
								'postInfo' => print_r($_POST, true),
								'additionalInfo' => "Transação não foi autorizada pelo Pagseguro. Por favor avisar responsáveis técnicos do sistema. /n".
								'Codigo de direcionamento PS: '.$notificationCode,
								'serverVars' => print_r($_SERVER, true));
						$site =& Request::getSite();
						$emailSuporteGeral = array($site->getLocalizedContactEmail());
						$this->enviarEmail('PAGSEGURO_INVESTIGAR_PAGAMENTO', $schedConf, $dadosDoEmail, $emailSuporteGeral);
						exit();
					}


					// Pega dados da transação PS
					$transaction = simplexml_load_string($transaction);
					$pagseguroId = $transaction->code;
					$requisicaoID = $transaction->reference;
					$psstatus = $transaction->status;
					$psData = new DateTime($transaction->lastEventDate);
					$psData = $psData->format("Y-m-d H:i:s");
					$emailComprador = $transaction->sender->email;

					// Recupera queuedPayment
					$pagseguroDao =& DAORegistry::getDAO('PagseguroDAO');
					$requisicaoPS = $pagseguroDao->getRequestPS($requisicaoID);
					import('payment.ocs.OCSPaymentManager');
					$ocsPaymentManager =& OCSPaymentManager::getManager();
					$queuedPayment =& $ocsPaymentManager->getQueuedPayment($requisicaoPS->getQueuedPaymentId());


					// Verifica alguma informação importante faltando
					if (!$requisicaoPS || !$pagseguroId || !$transaction || !$psstatus || !$requisicaoID) {
						// Caso for transação duplicada não faz nada
						if ($pagseguroDao->isNotificacaoDuplicada($pagseguroId, $psstatus)) exit();
												$dadosDoEmail = array(
								'schedConfName' => $schedConf->getFullTitle(),
								'motivo' => 'Foi recebido uma notificação com dados inconsistentes.',
								'postInfo' => print_r($_POST, true),
								'additionalInfo' => "queuedPaymentID: ".$queuedPaymentId.", pagseguroId: "
									.$pagseguroId.", transaction:".$transaction.", psstatus:".$psstatus.", requisicaoID:".$requisicaoID,
								'serverVars' => print_r($_SERVER, true));
						$site =& Request::getSite();
						$emailSuporteGeral = array($site->getLocalizedContactEmail());
						$this->enviarEmail('PAGSEGURO_INVESTIGAR_PAGAMENTO', $schedConf, $dadosDoEmail, $emailSuporteGeral);
						exit();
					}


					if($pagseguroDao->isPrimeiraNotificacao($pagseguroId))
						$pagseguroDao->updateRequest($requisicaoID, $pagseguroId, $emailComprador);
					else
						if ($pagseguroDao->isNotificacaoDuplicada($pagseguroId, $psstatus)) exit();

					//Grava transação no banco
					$pagseguroDao->insertTransaction(
							$pagseguroId,
							$psData,
							$psstatus);


					switch ($psstatus) {
						case self::PSSTATUS_AGURDANDO:
						case self::PSSTATUS_EM_ANALISE:
							exit();
							
						case self::PSSTATUS_PAGO:

							//Realiza compra e envia email para comprador notificando que o pagamento foi aceito
							if ($ocsPaymentManager->fulfillQueuedPayment($queuedPaymentId, $queuedPayment)) {

								$schedConfSettingsDao =& DAORegistry::getDAO('SchedConfSettingsDAO');

								// Pega nome e email do comprador
								$userDao =& DAORegistry::getDAO('UserDAO');
								$user =& $userDao->getUser($queuedPayment->getuserId());
								$registrantName = $user->getFullName();
								$registrantEmail = $user->getEmail();

								// Pega os detalhes da conferência
								$schedConfId = $schedConf->getId();
								$registrationName = $schedConfSettingsDao->getSetting($schedConfId, 'registrationName');
								$registrationEmail = $schedConfSettingsDao->getSetting($schedConfId, 'registrationEmail');
								$registrationPhone = $schedConfSettingsDao->getSetting($schedConfId, 'registrationPhone');
								$registrationFax = $schedConfSettingsDao->getSetting($schedConfId, 'registrationFax');
								$registrationMailingAddress = $schedConfSettingsDao->getSetting($schedConfId, 'registrationMailingAddress');
								$registrationContactSignature = $registrationName;

								if ($registrationMailingAddress != '') $registrationContactSignature .= "\n" . $registrationMailingAddress;
								if ($registrationPhone != '') $registrationContactSignature .= "\n" . AppLocale::Translate('user.phone') . ': ' . $registrationPhone;
								if ($registrationFax != '')	$registrationContactSignature .= "\n" . AppLocale::Translate('user.fax') . ': ' . $registrationFax;

								$registrationContactSignature .= "\n" . AppLocale::Translate('user.email') . ': ' . $registrationEmail;

								$paramArray = array(
										'registrantName' => $registrantName,
										'conferenceName' => $schedConf->getFullTitle(),
										'registrationContactSignature' => $registrationContactSignature
								);

								import('mail.MailTemplate');
								$mail = new MailTemplate('MANUAL_PAYMENT_RECEIVED');
								$mail->setFrom($registrationEmail, $registrationName);
								$mail->assignParams($paramArray);
								$mail->addRecipient($registrantEmail, $registrantName);
								$mail->send();
							}
							exit();
							
						case self::PSSTATUS_DISPONIVEL:
						case self::PSSTATUS_EM_DISPUTA:
							//Avisa Gerente de Inscrição
							//Pega dados do gerente de inscrição
							$schedConfSettingsDao =& DAORegistry::getDAO('SchedConfSettingsDAO');
							$gerenteInscricaoEmail = array($schedConfSettingsDao->getSetting($schedConf->getId(), 'registrationEmail'));

							$userDao =& DAORegistry::getDAO('UserDAO');
							$user =& $userDao->getUser($requisicaoPS->getUserId());
							$dadosDoEmail = array(
									'additionalInfo' => 'Conferencia: '.$schedConf->getId().' - '.$schedConf->getFullTitle().'/n'.
														'Usuário: '.$user->getId().' - '.$user->getUsername().'/n'.
														'E-mail: '.$user->getEmail());

							$this->enviarEmail('PAGSEGURO_TRANSC_EM_DISPUTA', $schedConf, $dadosDoEmail, $gerenteInscricaoEmail);
							exit();
							
						case self::PSSTATUS_DEVOLVIDA:
						case self::PSSTATUS_CANCELADA:
							//Avisar usuário que compra foi cancelada
							// Pega nome e email do comprador
							$userDao =& DAORegistry::getDAO('UserDAO');
							$user =& $userDao->getUser($requisicaoPS->getUserId());
							$registrantEmail = array($user->getEmail());
							$dadosDoEmail = array('schedConfName' => $schedConf->getFullTitle());

							$this->enviarEmail('PAGSEGURO_TRANSC_CANCELADA', $schedConf, $dadosDoEmail, $registrantEmail);
							exit();
					}
				}
		}
	}

	function enviarEmail($template, $schedConf, $dadosDoEmail, $emailDosDestinatarios = null) {
		$contactName = $schedConf->getSetting('contactName');
		$contactEmail = $schedConf->getSetting('contactEmail');

		import('mail.MailTemplate');
		$mail = new MailTemplate($template);
		$mail->setFrom($contactEmail, $contactName);
		$mail->assignParams($dadosDoEmail);

		if(empty($emailDosDestinatarios)) {
			$mail->addRecipient($contactEmail, $contactName);
		} else {
			if (is_array($emailDosDestinatarios)) {
				foreach ($emailDosDestinatarios as $emailDestinatario) {
					$mail->addRecipient($emailDestinatario);
				}
			} else {
				$mail->addRecipient($emailDosDestinatarios);
			}
		}

		$mail->send();
	}

}

?>