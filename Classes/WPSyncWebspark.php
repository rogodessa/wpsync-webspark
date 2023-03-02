<?php

namespace WC_Import\Classes;

use WC_Product;

class WPSyncWebspark
{
	protected array $productsList;

	private string $apiUrl;

	private array $skuList;

	private int $restartRemoteAfter;

	public function __construct()
	{
		$this->apiUrl = 'https://wp.webspark.dev/wp-api/products';

		$this->productsList = [];
		$this->skuList = [];

		$this->restartRemoteAfter = 60;

		add_action('plugins_loaded', [$this, 'init']);
	}

	public function init()
	{
		if(!class_exists('WooCommerce')) {
			add_action( 'admin_notices', [$this, 'admin_notice_error'] );
		}

		else {
			// start import by wp_cron
			add_action( 'wpd_wc_import_start', [$this, 'getProducts'] );
			// hook for restart import by woocommerce queue if API return bad request
			add_action( 'wpd_wc_import_remote_restart', [$this, 'getProducts'] );
			// hook for import one product by woocommerce queue
			add_action( 'wpd_wc_import_product', [$this, 'getProducts'] );
			// hook for delete one product by sku
			add_action( 'wpd_wc_delete_product', [$this, 'delete'] );

			if ( ! wp_next_scheduled( 'wpd_wc_import_start' ) ) {
				wp_schedule_event( time(), 'hourly', 'wpd_wc_import_start' );
			}
		}
	}

	/**
	 * Notice for admin if woocommerce not installed
	 */
	public function admin_notice_error()
	{
		$class = 'notice notice-error';
		$message = WPD_WC_PLUGIN_NAME . ': ' . __( 'Requires woocommerce plugin to be installed.', 'wc-import' );

		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * Get products list by api
	 * @return void
	 */
	public function getProducts()
	{
		$result = wp_remote_request(
			$this->apiUrl,
			[
				'method' => 'GET',
				'sslverify' => false
			]
		);

		if(is_wp_error($result)) {

			$next_timestamp = current_time('timestamp') + $this->restartRemoteAfter;
			WC()->queue()->schedule_single(
				$next_timestamp,
				'wpd_wc_import_remote_restart'
			);

		}
		else {
			$body = json_decode($result['body']);
			if(!$body->error && !empty($body->data)) {
				$this->productsList = $body->data;
				$this->setToImport();
			}
		}
	}

	private function setToImport()
	{
		if(!empty($this->productsList)) {
			foreach($this->productsList as $key => $item) {
				$this->skuList[] = $item->sku;

				WC()->queue()->add(
					'wpd_wc_import_product',
					['product' => $item]
				);
			}
			$this->findForDelete();
		}
	}

	public function importProduct($product) {
		$product = (object) $product;

		$product_id = wc_get_product_id_by_sku($product->sku);

		if(!empty($product_id))
			$this->createOrUpdate($product, $product_id);
		else
			$this->createOrUpdate($product);
	}

	public function createOrUpdate($product, $productId = 0)
	{
		$objProduct = new WC_Product($productId);
		if($productId == 0) {
			$objProduct->set_sku( $product->sku );
		}
		$objProduct->set_name($product->name);
		$objProduct->set_description($product->description);

		$price = number_format(str_replace('$', '', $product->price), 2);
		$objProduct->set_regular_price($price);

		$objProduct->set_stock_status('instock');
		$objProduct->set_manage_stock(true);
		$objProduct->set_stock_quantity($product->in_stock);
		$objProduct->set_status('publish');
		$objProduct->set_catalog_visibility('visible');

		$objProduct->set_category_ids([16]);

		if( empty($objProduct->get_image_id()) ) {
			if ( ! empty( $product->picture ) && filter_var( $product->picture, FILTER_VALIDATE_URL ) ) {
				$attachment_id = $this->upload_file( $product->picture, $product->sku );
				$objProduct->set_image_id( $attachment_id );
			}
		}

		return $objProduct->save();
	}


	/**
	 * Upload image from URL
	 */
	private function upload_file($image_url, $sku)
	{
		$fil_name = 'image-product-' . $sku . '.jpg';

		// get image file content from loremflickr.com
		$response = wp_remote_get($image_url);

		if(empty($response['body']))
			return false;

		// create file on the server
		$created_file = wp_upload_bits($fil_name, null, $response['body']);

		if($created_file['error'] || empty($created_file['file']))
			return false;

		// save attachment info to db
		$attachment_id = wp_insert_attachment(
			array(
				'guid'           => $created_file[ 'url' ],
				'post_mime_type' => wp_get_image_mime($created_file['file']),
				'post_title'     => basename( $created_file['file'] ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$created_file['file']
		);

		if( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return false;
		}

		return $attachment_id;

	}


	private function findForDelete()
	{
		global $wpdb;

		if(empty($this->skuList)) return;

		$results = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE `meta_key` = '_sku'");

		$skuForDelete = array_diff($results, $this->skuList);

		if(!empty($skuForDelete)) {
			foreach ($skuForDelete as $sku) {
				WC()->queue()->add(
					'wpd_wc_delete_product',
					['sku' => $sku]
				);
			}
		}
	}

	private function delete($sku) {
		if(empty($sku)) return;
		$product_id = wc_get_product_id_by_sku($sku);

		if(!empty($product_id)) {
			$product = new WC_Product($product_id);
			$product->delete(true);
		}
	}
}
