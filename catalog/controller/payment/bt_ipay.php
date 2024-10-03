<?php
namespace Opencart\Catalog\Controller\Extension\IpayOpencart\Payment;

use Opencart\System\Engine\Controller;
use BtIpay\Opencart\Payment\PaymentErrorException;
use Bt_Ipay;
class BtIpay extends Controller
{

    /**
     * Display payment form on checkout page
     *
     * @return void
     */
    public function index()
    {
        $this->load->language('extension/ipay_opencart/payment/bt_ipay');

        $paymentModel = $this->getModel();
        $cards = $this->customer->isLogged() ? $this->getLib()->decryptCardList($paymentModel->getEnabledCustomerCards(intval($this->customer->getId()))) : [];
        return $this->load->view('extension/ipay_opencart/payment/bt_ipay', [
            "cofEnabled" => $paymentModel->isCofEnabled() && $this->customer->isLogged(),
            "cards" => $cards,
            "startPayAction" => $this->url->link('extension/ipay_opencart/payment/bt_ipay.start', '', true)
        ]);
    }


    /**
     * Start payment endpoint
     *
     * @return void
     */
    public function start()
    {
        if (
            !isset($this->session->data['order_id']) ||
            !is_scalar($this->session->data['order_id'])
        ) {
            $this->json(["error" => true, "message" => "Could not find order, refresh the page"]);
            return;
        }
        $response = $this->getLib()->startPayment(
            $this->request->post,
            $this->customer,
            $this->getModel(),
            intval($this->session->data['order_id']),
            $this->url->link('extension/ipay_opencart/payment/bt_ipay.finish', '', true),
            $this->config->get('config_language_admin')
        );
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response));
    }

    /**
     * Finish payment page endpoint
     *
     * @return void
     */
    public function finish()
    {
        $this->load->language('extension/ipay_opencart/payment/bt_ipay');
        if (
            !isset($this->request->get['orderId']) ||
            !is_string($this->request->get['orderId'])
        ) {
            $this->redirectToFailed($this->language->get('missing_return_order_number'));
        }
        try {
            $this->getLib()->finishPayment(
                $this->getModel(),
                $this->request->get['orderId'],
                $this->config->get('config_language_admin')
            );
        } catch (PaymentErrorException $th) {
            $this->redirectToFailed(
                $this->language->get('failed_payment_with_message') . $th->getMessage()
            );
        } catch (\Throwable $th) {
            $this->redirectToFailed($this->language->get('failed_to_process_return'));
        }
        $this->response->redirect($this->url->link('checkout/success', '', true));
    }

    /**
     * Render card area
     *
     * @param mixed $route
     * @param mixed $data
     * @param string $output
     *
     * @return bool
     */
    public function render_cof(&$route, &$data, &$output): bool
	{
        if (!is_string($output)) {
            return false;
        }
        $this->load->language('extension/ipay_opencart/payment/bt_ipay');
        $link = $this->url->link('extension/ipay_opencart/payment/bt_ipay.cards', '', true);
        $name = $this->language->get('bt_ipay_cof_name');
		if ($this->getModel()->isCofEnabled()) {
            $output.= '<div class="list-group"><a href="'.$link.'" class="list-group-item">'.$name.'</a></div>';
        }
        return false;
	}

    /**
     * Flash error to the error page
     *
     * @param mixed $route
     * @param mixed $data
     * @param string $output
     *
     * @return boolean
     */
    public function render_flash(&$route, &$data, &$output): bool
    {
        $this->load->language('extension/ipay_opencart/payment/bt_ipay');
        if (!is_string($output)) {
            return false;
        }
        $data['button_continue'] = $this->language->get('button_continue');

        if (
            isset($this->session->data['bt_flash_error']) &&
            is_string($this->session->data['bt_flash_error']) &&
            isset($this->request->get['route']) &&
            $this->request->get['route'] === "checkout/failure"
        ) {
            $data['text_message'] = '<div class="alert alert-danger">'.$this->session->data['bt_flash_error'].'</div>';
            unset($this->session->data['bt_flash_error']);
        }
        return false;
    }


    /**
     * Display the card management page
     *
     * @return void
     */
    public function cards()
    {
        $this->redirectToAccountIfCofDisabled();

        $this->load->language('extension/ipay_opencart/payment/bt_ipay');

        $this->document->setTitle($this->language->get('cards_heading_title'));

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/home', 'customer_token=' . $this->session->data['customer_token'])
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_account'),
            'href' => $this->url->link('account/account', 'customer_token=' . $this->session->data['customer_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('bt_ipay_cof_name'),
            'href' => $this->url->link('extension/payment/bt_ipay/cards', '', true)
        );

        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];

            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        if (isset($this->session->data['error'])) {
            $data['error'] = $this->session->data['error'];

            unset($this->session->data['error']);
        } else {
            $data['error'] = '';
        }

        $data['actionDelete'] = $this->url->link('extension/ipay_opencart/payment/bt_ipay.delete_card', '', true);
        $data['actionToggle'] = $this->url->link('extension/ipay_opencart/payment/bt_ipay.toggle_card', '', true);
        $data['actionCreate'] = $this->url->link('extension/ipay_opencart/payment/bt_ipay.create_card', '', true);
        $data['title'] = $this->language->get('bt_ipay_cof_name');
        $data['cards'] = $this->getLib()->decryptCardList($this->getModel()->getCustomerCards($this->customer->getId()));
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('extension/ipay_opencart/payment/bt_ipay_cards', $data));
    }

    public function create_card()
    {
        $this->redirectToAccountIfCofDisabled();
        $this->load->language('extension/ipay_opencart/payment/bt_ipay');
        $redirectUrl = $this->getLib()->createCard(
            $this->getModel(),
            $this->config->get('config_language'),
            $this->url->link('extension/ipay_opencart/payment/bt_ipay.finish_card_create', '', true),
            $this->customer->getId()
        );

        if ($redirectUrl === null) {
            $this->flash('could_not_start_card_verification', true);
            $this->response->redirect($this->url->link('extension/ipay_opencart/payment/bt_ipay.cards', '', true));
        }
        $this->response->redirect($redirectUrl);
    }

    private function redirectToAccountIfCofDisabled() {
        if (!$this->customer->isLogged()) {
            $this->session->data['redirect'] = $this->url->link('account/account', true);

            $this->response->redirect($this->url->link('account/login', true));
        }
        
        if (!$this->getModel()->isCofEnabled()) {
            $this->session->data['redirect'] = $this->url->link('account/account', 'customer_token=' . $this->session->data['customer_token'], true);

            $this->response->redirect($this->url->link('account/login', 'customer_token=' . $this->session->data['customer_token'], true));
        }
    }

    public function finish_card_create()
    {
        $this->redirectToAccountIfCofDisabled();
        $this->load->language('extension/ipay_opencart/payment/bt_ipay');
        if (!isset( $this->request->get['orderId']) || !is_scalar($this->request->get['orderId'])) {
            $this->flash('could_not_start_card_verification');
            $this->response->redirect($this->url->link('extension/ipay_opencart/payment/bt_ipay.cards', '', true));
        }
        $orderId = $this->request->get['orderId'];

        $result = $this->getLib()->finishCardCreate(
            $this->getModel(),
            $this->config->get('config_language_admin'),
            $orderId
        );

        if (isset($result['error'], $result['message'])) {
            $this->flash($result['message'], $result['error']);
        }

        $this->response->redirect($this->url->link('extension/ipay_opencart/payment/bt_ipay.cards', '', true));
    }

    /**
     * Enable or disable card
     *
     * @return void
     */
    public function toggle_card()
    {
        $this->redirectToAccountIfCofDisabled();
        $this->load->language('extension/ipay_opencart/payment/bt_ipay');
        if (!$this->cardIdExists()) {
            $this->flash('missing_card_id', true);
        }
        $cardId = intval($this->request->post['cardId']);
        $ipayId = $this->getModel()->getCardIpayId(
            $cardId,
            intval($this->customer->getId())
        );

        if ($ipayId === null) {
            $this->flash('card_not_found', true);
            $this->response->redirect($this->url->link('extension/ipay_opencart/payment/bt_ipay.cards', '', true));
        }

        $enable = isset($this->request->post['enable']);

        $changed = $this->getLib()->toggleCard(
            $this->getModel(),
            $this->config->get('config_language_admin'),
            $ipayId,
            $enable
        );

        if ($changed) {
            $this->getModel()->saveCardStatus($cardId, $this->customer->getId(), $enable);
            $this->flash('card_status_changed');
        } else {
            $this->flash('failed_to_change_card_status', true);
        }

        $this->response->redirect($this->url->link('extension/ipay_opencart/payment/bt_ipay.cards', '', true));
    }

    /**
     * Delete card from db
     *
     * @return void
     */
    public function delete_card()
    {
        $this->redirectToAccountIfCofDisabled();
        $this->load->language('extension/ipay_opencart/payment/bt_ipay');
        if (!$this->cardIdExists()) {
            $this->flash('missing_card_id', true);
        }

        $model =  $this->getModel();

        $card = $model->getCardById(intval($this->request->post['cardId']));

        if (isset($card['ipay_id']))
        {
            $this->getLib()->toggleCard(
                $this->getModel(),
                $this->config->get('config_language_admin'),
                $card['ipay_id'],
                false
            );
    
        }
       
        $deleted = $model->deleteCardById(
            intval($this->request->post['cardId']),
            intval($this->customer->getId())
        );

        if ($deleted) {
            $this->flash('card_deleted_successfully');
        } else {
            $this->flash('could_not_delete_card', true);
        }
        $this->response->redirect($this->url->link('extension/ipay_opencart/payment/bt_ipay.cards', '', true));
    }

    public function callback()
    {
        $wasProcessed = $this->getLib()->callback(
            $this->getModel(),
            $this->config->get('config_language_admin')
        );

        if (!$wasProcessed) {
            header('HTTP/1.1 400 Bad Request', true, 400);
        }
    }

    /**
     * Add error notices into session to be displayed on redirect
     *
     * @param string $message
     * @param boolean $error
     *
     * @return void
     */
    private function flash(string $message, bool $error = false)
    {
        $type = 'success';
        if ($error) {
            $type = 'error';
        }
        $this->session->data[$type] = $this->language->get($message);
    }

    /**
     * Check if request has cardId
     *
     * @return boolean
     */
    private function cardIdExists(): bool
    {
        return isset($this->request->post['cardId']) &&
            is_scalar($this->request->post['cardId']) &&
            intval($this->request->post['cardId']) > 0;
    }

    /**
     * Redirect back to checkout with error message
     *
     * @param string $message
     *
     * @return void
     */
    private function redirectToFailed(string $message)
    {
        $this->session->data['bt_flash_error'] = $message;
        
        if (isset($this->session->data['order_id'])) {
			$this->cart->clear();
			unset($this->session->data['order_id']);
		}

        $this->response->redirect(
            $this->url->link(
                'checkout/failure', 
                'language=' . $this->config->get('config_language'),
                true
            )
        );
    }

    /**
     * Return json output
     *
     * @param array $response
     *
     * @return void
     */
    private function json(array $response)
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response));
        return;
    }

    private function getLib(): Bt_Ipay
    {
        include_once( DIR_EXTENSION.'ipay_opencart/system/library/bt_ipay.php' );
        return new Bt_Ipay();
    }

    /**
     * Get our bt ipay model
     *
     * @return ModelExtensionPaymentBtIpay
     */
    private function getModel()
    {
            $this->load->model('extension/ipay_opencart/payment/bt_ipay');
        return $this->model_extension_ipay_opencart_payment_bt_ipay;
    }


}