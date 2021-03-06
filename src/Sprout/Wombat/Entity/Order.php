<?php

namespace Sprout\Wombat\Entity;

class Order {

	/**
	 * @var array $data Hold the JSON object data retrieved from the source
	 */
	protected $data;

	/**
	 * @var array $_attached_resources Field names for data not contained in the main object that will need to be retrieved
	 */
	private $_attached_resources = array('products','shipping_addresses','coupons');
	
	/**
	 * @var array $client Http client object to perform additional requests
	 */
	private $client;

	/**
	 * @var array $request_data Data about the request that we've been sent
	 */
	private $request_data;

	public function __construct($data, $type='bc', $client, $request_data) {
		$this->data[$type] = $data;
		$this->client = $client;
		$this->request_data = $request_data;
	}

	/**
	 * Get a Shipment object instead of an Order object
	 */
	public function getWombatShipmentObject() {
		if(isset($this->data['wombat_shipment']))
			return $this->data['wombat_shipment'];
		else if(isset($this->data['bc']))
			$bc_obj = (object) $this->data['bc'];
		else
			return false;

		$wombat_obj = (object) array(
			'id'	=> strtoupper($this->getHashId($bc_obj->id)).'-S',
			'order_id' => strtoupper($this->getHashId($bc_obj->id)),
			'email' => $bc_obj->billing_address->email,
			'cost' => (float) number_format($bc_obj->shipping_cost_ex_tax, 2, '.', ''),
			'status' => 'ready',
			'order_status' => strtolower($bc_obj->status),
			'stock_location' => 'default',
			'shipping_method' => $bc_obj->_shipping_address->shipping_method,
			'tracking' => '',

			);

		if(!empty($bc_obj->_shipping_address)) {
			$wombat_obj->shipping_address = (object) array(
				//'id' => $bc_obj->_shipping_address->id,
				'firstname' => $bc_obj->_shipping_address->first_name,
				'lastname' => $bc_obj->_shipping_address->last_name,
				'address1' => $bc_obj->_shipping_address->street_1,
				'address2' => $bc_obj->_shipping_address->street_2,
				'zipcode' => $bc_obj->_shipping_address->zip,
				'city' => $bc_obj->_shipping_address->city,
				'state' => $bc_obj->_shipping_address->state,
				'country' => $bc_obj->_shipping_address->country_iso2,
				'phone' => $bc_obj->_shipping_address->phone,
				'bigcommerce_id' => $bc_obj->_shipping_address->id,
			);
		}

		/*** LINE_ITEMS ***/
		foreach($bc_obj->products as $bc_prod) {
			$new_line_item = (object) array(
				'product_id' => empty($bc_prod->sku) ? $bc_prod->product_id : $bc_prod->sku,
				'name' => $bc_prod->name,
				'quantity' => $bc_prod->quantity,
				'price' => (float) number_format($bc_prod->price_ex_tax, 2, '.', ''),
				'bigcommerce_id' => $bc_prod->id,
				'bigcommerce_product_id' => $bc_prod->product_id,
			);
			
			// add chosen product options to line item
			if(!empty($bc_prod->product_options)) {
				$new_line_item->options = array();
				foreach($bc_prod->product_options as $bc_option) {
					$option_key = $bc_option->display_name;
					$option_val = $bc_option->display_value;
					$new_option = (object) array(
						$option_key => $option_val
					);
					$new_line_item->options[] = $new_option;
				}
			}
			
			$wombat_obj->items[] = $new_line_item;
		}
		$this->data['wombat'] = $wombat_obj;
		return $wombat_obj;

	}

	/**
	 * Get a Wombat-formatted set of data from a BigCommerce one.
	 */
	public function getWombatObject() {
		if(isset($this->data['wombat']))
			return $this->data['wombat'];
		else if(isset($this->data['bc']))
			$bc_obj = (object) $this->data['bc'];
		else
			return false;
		
		/*** WOMBAT OBJECT ***/
		$wombat_obj = (object) array(
			'id' => strtoupper($this->getHashId($bc_obj->id)),
			'status' => strtolower($bc_obj->status),
			'channel' => 'bigcommerce_'.$this->request_data['hash'].'_'.(is_null($bc_obj->external_source) ? $bc_obj->order_source : $bc_obj->external_source),
			'email' => $bc_obj->billing_address->email,
			'currency' => $bc_obj->currency_code,
			'placed_on' => date('c',strtotime($bc_obj->date_created)),
			'totals' => (object) array(
				'item' =>  (float) number_format($bc_obj->subtotal_ex_tax, 2, '.', ''),
				'adjustment' => 0,
				'discount' => (float) number_format(($bc_obj->discount_amount + $bc_obj->coupon_discount) * -1.0, 2, '.', ''),
				'tax' => (float) number_format($bc_obj->total_tax, 2, '.', ''),
				'shipping' => (float) number_format($bc_obj->shipping_cost_ex_tax, 2, '.', ''),
				'payment' => (float) number_format($bc_obj->total_inc_tax, 2, '.', ''),
				'order' => (float) number_format($bc_obj->total_inc_tax, 2, '.', ''),
                'order_message' => $bc_obj->customer_message,				
			),
			'line_items' => array(),
			'adjustments' => array(),
			
			'billing_address' => (object) array(
				//'id' => $bc_obj->_shipping_address->id,
				'firstname' => $bc_obj->billing_address->first_name,
				'lastname' => $bc_obj->billing_address->last_name,
				'address1' => $bc_obj->billing_address->street_1,
				'address2' => $bc_obj->billing_address->street_2,
				'zipcode' => $bc_obj->billing_address->zip,
				'city' => $bc_obj->billing_address->city,
				'state' => $bc_obj->billing_address->state,
				'country' => $bc_obj->billing_address->country_iso2,
				'phone' => $bc_obj->billing_address->phone,
			),
			'payments' => array(),
			'bigcommerce_id' => $bc_obj->id,
		);

		if(!empty($bc_obj->_shipping_address)) {
			$wombat_obj->shipping_address = (object) array(
				//'id' => $bc_obj->_shipping_address->id,
				'firstname' => $bc_obj->_shipping_address->first_name,
				'lastname' => $bc_obj->_shipping_address->last_name,
				'address1' => $bc_obj->_shipping_address->street_1,
				'address2' => $bc_obj->_shipping_address->street_2,
				'zipcode' => $bc_obj->_shipping_address->zip,
				'city' => $bc_obj->_shipping_address->city,
				'state' => $bc_obj->_shipping_address->state,
				'country' => $bc_obj->_shipping_address->country_iso2,
				'phone' => $bc_obj->_shipping_address->phone,
				'bigcommerce_id' => $bc_obj->_shipping_address->id,
			);
		}

		/*** LINE_ITEMS ***/
		foreach($bc_obj->products as $bc_prod) {
			$new_line_item = (object) array(
				'product_id' => empty($bc_prod->sku) ? $bc_prod->product_id : $bc_prod->sku,
				'name' => $bc_prod->name,
				'quantity' => $bc_prod->quantity,
				'price' => (float) number_format($bc_prod->price_ex_tax, 2, '.', ''),
				'bigcommerce_id' => $bc_prod->id,
				'bigcommerce_product_id' => $bc_prod->product_id,
			);
			
			// add chosen product options to line item
			if(!empty($bc_prod->product_options)) {
				$new_line_item->options = array();
				foreach($bc_prod->product_options as $bc_option) {
					$option_key = $bc_option->display_name;
					$option_val = $bc_option->display_value;
					$new_option = (object) array(
						$option_key => $option_val
					);
					$new_line_item->options[] = $new_option;
				}
			}
			
			$wombat_obj->line_items[] = $new_line_item;
		}

		/*** ADJUSTMENTS ***/
		if($bc_obj->total_tax > 0) { // TAX
			$wombat_obj->adjustments[] = (object) array(
				'name' => 'Tax',
				'value' => (float) number_format($bc_obj->total_tax, 2, '.', '')
			);
			//$wombat_obj->totals->adjustment += $bc_obj->total_tax;
		}
		
		if($bc_obj->wrapping_cost_ex_tax > 0) { // GIFT WRAPPING
			$wombat_obj->adjustments[] = (object) array(
				'name' => 'Gift Wrapping',
				'value' => (float) number_format($bc_obj->wrapping_cost_ex_tax, 2, '.', '')
			);
			$wombat_obj->totals->adjustment += $bc_obj->wrapping_cost_ex_tax;
		}
		
		if($bc_obj->shipping_cost_ex_tax > 0) { // SHIPPING
			$wombat_obj->adjustments[] = (object) array(
				'name' => 'Shipping',
				'value' => (float) number_format($bc_obj->shipping_cost_ex_tax, 2, '.', '')
			);
			$wombat_obj->totals->adjustment += $bc_obj->shipping_cost_ex_tax;
		}
		if($bc_obj->handling_cost_ex_tax > 0) { // HANDLING
			$wombat_obj->adjustments[] = (object) array(
				'name' => 'Handling',
				'value' => (float) number_format($bc_obj->handling_cost_ex_tax, 2, '.', '')
			);
			$wombat_obj->totals->adjustment += $bc_obj->handling_cost_ex_tax;
		}
		if($bc_obj->coupon_discount > 0) { // COUPONS
			$wombat_obj->adjustments[] = (object) array(
				'name' => 'Coupons',
				'value' => (float) number_format($bc_obj->coupon_discount * -1, 2, '.', '')
			);
		}
		if($bc_obj->discount_amount > 0) { // DISCOUNT
			$wombat_obj->adjustments[] = (object) array(
				'name' => 'Discount',
				'value' => (float) number_format($bc_obj->discount_amount * -1, 2, '.', '')
			);
		}
		
		/*** PAYMENTS ***/
		$wombat_obj->payments[] = (object) array(
			'number' => $this->getPaymentNumber($bc_obj),
			'status' => $this->getPaymentStatus($bc_obj),
			'amount' => (float) number_format($bc_obj->total_inc_tax, 2, '.', ''),
			'payment_method' => $bc_obj->payment_method
		);

		$this->data['wombat'] = $wombat_obj;
		return $wombat_obj;
	}

	/**
	 * Get a BigCommerce-formatted set of data from a Wombat one.
	 */
	public function getBigCommerceObject($action = 'create') {
		if(isset($this->data['bc']))
			return $this->data['bc'];
		else if(isset($this->data['wombat']))
			$wombat_obj = (object) $this->data['wombat'];
		else
			return false;
		
		// @todo: real data
		if($action == 'create') { //this distinction is temporary for testing data, but we may use it for individual fields
			$bc_obj = (object) array(
				'products' => array(
					(object) array(
						'product_id' => 107,
						'quantity' => rand(1,10),
						),
					(object) array(
						'product_id' => 84,
						'quantity' => rand(1,10),
						),

					),
				'billing_address' => (object) array(
			    'first_name' => 'Some',
			    'last_name' => 'Person',
			    'company' => '',
			    'street_1' => '123 Some St',
			    'street_2' => '',
			    'city' => 'Austin',
			    'state' => 'Texas',
			    'zip' => '78757',
			    'country' => 'United States',
			    'country_iso2' => 'US',
			    'phone' => '',
			    'email' => 'some.person@example.com',
	  		),
			);
		} else {
			$bc_obj = (object) array(
				// 'billing_address' => (object) array(
				// 	'first_name' => $wombat_obj->billing_address['firstname'],
			 //    'last_name' => $wombat_obj->billing_address['lastname'],
			 //    'company' => '',
			 //    'street_1' => $wombat_obj->billing_address['address1'],
			 //    'street_2' => $wombat_obj->billing_address['address2'],
			 //    'city' => $wombat_obj->billing_address['city'],
			 //    'state' => $wombat_obj->billing_address['state'],
			 //    'zip' => $wombat_obj->billing_address['zipcode'],
			 //    'country' => $wombat_obj->billing_address['country'], // @todo: map codes onto names?
			 //    'country_iso2' => $wombat_obj->billing_address['country'],
			 //    'phone' => $wombat_obj->billing_address['phone'],
			 //    'email' => $wombat_obj->email, // @todo: wombat only has one email per order: override billing/shipping ones, or no?
				// ),
				'staff_notes' => 'Updating an order!',
			);
		}
	
		$this->data['bc'] = $bc_obj;
		return $bc_obj;
	}

	/**
	 * Return the BigCommerceID for this object
	 */
	public function getBCID() {
		if(!empty($this->data['wombat']['bigcommerce_id'])) {
			return $this->data['wombat']['bigcommerce_id'];
		}
		$hash = $this->request_data['hash'];
		$id = $this->data['wombat']['id'];

		if((stripos($id, $hash) !== false) && (strlen($id) >= strlen($hash))) {
			$id = str_ireplace($hash.'-', '', $id);
		}
		return $id;
	}
	
	/**
	 * Perform any sub-requests to load additional resources
	 */
	public function loadAttachedResources()
	{
		$client = $this->client;
		$request_data = $this->request_data;

		// request attached resources		
		foreach($this->_attached_resources as $resource_name) {
			if(isset($this->data['bc']->$resource_name)) {
				$resource = $this->data['bc']->$resource_name;
			
				// don't load in resources with id 0 (they don't exist)
				if(strpos($resource->url,'\/0.json') === FALSE) {				
					// replace request shell with loaded resource
					$response = $client->get($resource->url);
					
					if(intval($response->getStatusCode()) === 200)
						$this->data['bc']->$resource_name = $response->json(array('object'=>TRUE));
					else
						$this->data['bc']->$resource_name = NULL;
				}
			}
		}
		
		// organize extra resources (not really in API)
		
		/* First shipping address */
		if(!empty($this->data['bc']->shipping_addresses)) {
			$this->data['bc']->_shipping_address = $this->data['bc']->shipping_addresses[0];
		}

	}

	/**
	 * Get payment number, or default if not set
	 */
	public function getPaymentNumber($bc_obj) {
		$number = "N/A";
		if(!is_null($bc_obj->payment_provider_id)) {
			$number = $bc_obj->payment_provider_id;
		}
		return $number;
	}

	/**
	 * Get payment status, or default if not set
	 */
	public function getPaymentStatus($bc_obj) {
		$status = "";
		if(!empty($bc_obj->payment_status)) {
			$status = $bc_obj->payment_status;
		} else {
			switch (strtoupper($bc_obj->payment_method)) {
				case 'MONEY ORDER':
				case 'CHECK':
				case 'PAY IN STORE':
				case 'CASH ON DELIVERY':
				case 'CASH':
				default:
					$status = "completed";
					break;
			}
		}
		return $status;
	}

	/**
	 * Add the store hash to the object ID
	 */
	public function getHashId($id) {
		$hash = $this->request_data['hash'];
		
		return $hash.'-'.$id;
	}
}