<?php
/**
 * Currency Prices plugin for Craft CMS 3.x
 *
 * Adds payment currency prices to products
 *
 * @link      https://kurious.agency
 * @copyright Copyright (c) 2018 Kurious Agency
 */

namespace kuriousagency\commerce\currencyprices;

use kuriousagency\commerce\currencyprices\services\CurrencyPricesService;
use kuriousagency\commerce\currencyprices\controllers\PaymentCurrenciesController;
use kuriousagency\commerce\currencyprices\adjusters\Shipping;
use kuriousagency\commerce\currencyprices\adjusters\Discount;
use kuriousagency\commerce\currencyprices\twigextensions\CurrencyPricesTwigExtension;
use kuriousagency\commerce\currencyprices\assetbundles\currencyprices\CurrencyPricesAsset;
use kuriousagency\commerce\currencyprices\fields\CurrencyField;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\web\UrlManager;
use craft\events\RegisterUrlRulesEvent;
use craft\web\View;
use craft\events\TemplateEvent;
use craft\services\Elements;
use craft\base\Element;
use craft\commerce\Plugin as Commerce;
use craft\commerce\elements\Order;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;

use craft\commerce\events\LineItemEvent;
use craft\commerce\services\LineItems;
use craft\commerce\events\ProcessPaymentEvent;
use craft\commerce\services\Payments;
use craft\commerce\services\OrderAdjustments;

use yii\base\Event;

/**
 * Class CurrencyPrices
 *
 * @author    Kurious Agency
 * @package   CurrencyPrices
 * @since     1.0.0
 *
 * @property  CurrencyPricesService $currencyPricesService
 */
class CurrencyPrices extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var CurrencyPrices
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
	public $schemaVersion = '1.1.0';
	

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
		self::$plugin = $this;
		
		$this->setComponents([
			'service' => CurrencyPricesService::class,
		]);

		Craft::$app->view->registerTwigExtension(new CurrencyPricesTwigExtension());
		
		if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(
                View::class,
                View::EVENT_BEFORE_RENDER_TEMPLATE,
                function (TemplateEvent $event) {
                    try {
                        Craft::$app->getView()->registerAssetBundle(CurrencyPricesAsset::class);
                    } catch (InvalidConfigException $e) {
                        Craft::error(
                            'Error registering AssetBundle - '.$e->getMessage(),
                            __METHOD__
                        );
                    }
                }
            );
		}

        // Event::on(
        //     UrlManager::class,
        //     UrlManager::EVENT_REGISTER_SITE_URL_RULES,
        //     function (RegisterUrlRulesEvent $event) {
        //         $event->rules['siteActionTrigger1'] = 'currency-prices/default';
        //     }
        // );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
				$event->rules['commerce-currency-prices/payment-currencies/delete'] = 'commerce-currency-prices/payment-currencies/delete';
				$event->rules['commerce-currency-prices/payment-currencies/all'] = 'commerce-currency-prices/payment-currencies/all';
            }
		);
		
		Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = CurrencyField::class;
            }
        );

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                }
            }
		);

		Event::on(Element::class, Element::EVENT_BEFORE_SAVE, function(Event $event) {
			if ($event->sender instanceof \craft\commerce\elements\Product) {
				$prices = Craft::$app->getRequest()->getBodyParam('prices');
				$newCount = 1;
				if ($prices) {
					foreach ($event->sender->variants as $key => $variant)
					{
						if ($variant->id && isset($prices[$variant->id])) {
							$price = $prices[$variant->id];
						} else {
							$price = $prices['new'.$newCount];
							$newCount++;
						}
						foreach ($price as $iso => $value)
						{
							if ($value == '') {
								$event->sender->variants[$key]->addError('prices-'.$iso, 'Price cannot be blank.');
								$event->isValid = false;
							}
						}
					}
				}
			}
			
			if ($event->sender instanceof \kuriousagency\commerce\bundles\elements\Bundle) {
				$prices = Craft::$app->getRequest()->getBodyParam('prices');

				if ($prices) {
					foreach ($prices as $iso => $value)
					{
						if ($value == '') {
							$event->sender->addError('prices-'.$iso, 'Price cannot be blank.');
							$event->isValid = false;
						}
					}
				}
			}

			if ($event->sender instanceof \craft\digitalproducts\elements\Product) {
				$prices = Craft::$app->getRequest()->getBodyParam('prices');
				
				if ($prices) {
					foreach ($prices as $iso => $value)
					{
						if ($value == '') {
							$event->sender->addError('prices-'.$iso, 'Price cannot be blank.');
							$event->isValid = false;
						}
					}
				}
			}
		});

		Event::on(Element::class, Element::EVENT_AFTER_SAVE, function(Event $event) {
			if ($event->sender instanceof \craft\commerce\elements\Product) {
				$prices = Craft::$app->getRequest()->getBodyParam('prices');
				$count = 0;
				if ($prices) {
					foreach ($prices as $key => $price)
					{
						if ($key != 'new') {
							$this->service->savePrices($event->sender->variants[$count], $price);
							$count++;
						}
					}
				}
			}
			
			if ($event->sender instanceof \kuriousagency\commerce\bundles\elements\Bundle) {
				$prices = Craft::$app->getRequest()->getBodyParam('prices');
				if ($prices) {
					$this->service->savePrices($event->sender, $prices);
				}
			}
			if ($event->sender instanceof \craft\digitalproducts\elements\Product) {
				$prices = Craft::$app->getRequest()->getBodyParam('prices');
				if ($prices) {
					$this->service->savePrices($event->sender, $prices);
				}
			}
		});

		Event::on(Element::class, Element::EVENT_AFTER_DELETE, function(Event $event) {
			//Craft::dd($event);
			if ($event->sender instanceof \craft\commerce\elements\Variant) {
				
				$this->service->deletePrices($event->sender->id);
			}
			if ($event->sender instanceof \kuriousagency\commerce\bundles\elements\Bundle) {
				
				$this->service->deletePrices($event->sender->id);
			}
			if ($event->sender instanceof \craft\digitalproducts\elements\Product) {
				
				$this->service->deletePrices($event->sender->id);
			}
		});

		Event::on(LineItems::class, LineItems::EVENT_POPULATE_LINE_ITEM, function(LineItemEvent $event) {

				$order = $event->lineItem->getOrder();
				$paymentCurrency = $order->getPaymentCurrency();
				$primaryCurrency = Commerce::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso();

				$prices = $this->service->getPricesByPurchasableId($event->lineItem->purchasable->id);
				if ($prices) {
					$price = $prices[$paymentCurrency];
					
					$salePrice = $this->service->getSalePrice($event->lineItem->purchasable, $paymentCurrency);
					$saleAmount = 0- ($price - $salePrice);

					$event->lineItem->snapshot['priceIn'] = $paymentCurrency;
					$event->lineItem->price = $price;
					$event->lineItem->saleAmount = $saleAmount;
					$event->lineItem->salePrice = $salePrice;
					//Craft::dd($event->lineItem);
				}
			}
		);

		Event::on(Order::class, Order::EVENT_BEFORE_COMPLETE_ORDER, function(Event $event) {
			$event->sender->currency = $event->sender->paymentCurrency;
		});

		Event::on(Payments::class, Payments::EVENT_BEFORE_PROCESS_PAYMENT, function(ProcessPaymentEvent $event) {
			$event->order->currency = $event->order->paymentCurrency;
		});

		Event::on(OrderAdjustments::class, OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, function(RegisterComponentTypesEvent $e) {
			foreach ($e->types as $key => $type)
			{
				if ($type == 'craft\\commerce\\adjusters\\Shipping') {
					$e->types[$key] = Shipping::class;
				}
				if ($type == 'craft\\commerce\\adjusters\\Discount') {
					//$e->types[$key] = Discount::class;
				}
			}
			//Craft::dd($e->types);
		});

		/*Event::on(Discount::class, Discount::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED, function(DiscountAdjustmentsEvent $e) {
			Craft::dd($e->adjustments);
			foreach ($e->adjustments as $key => $adjustment)
			{
				$price = (Object) CurrencyPrices::$plugin->service->getPricesByDiscountIdAndCurrency($e->discount->id, $e->order->paymentCurrency);
				if ($price) {
					foreach ($e->order->getLineItems() as $item) {
						if (in_array($item->id, $matchingLineIds, false)) {
							$adjustment = $this->_createOrderAdjustment($this->_discount);
							$adjustment->setLineItem($item);
			
							$amountPerItem = Currency::round($this->_discount->perItemDiscount * $item->qty);
			
							//Default is percentage off already discounted price
							$existingLineItemDiscount = $item->getAdjustmentsTotalByType('discount');
							$existingLineItemPrice = ($item->getSubtotal() + $existingLineItemDiscount);
							$amountPercentage = Currency::round($this->_discount->percentDiscount * $existingLineItemPrice);
			
							if ($this->_discount->percentageOffSubject == DiscountRecord::TYPE_ORIGINAL_SALEPRICE) {
								$amountPercentage = Currency::round($this->_discount->percentDiscount * $item->getSubtotal());
							}
			
							$adjustment->amount = $amountPerItem + $amountPercentage;
			
							if ($adjustment->amount != 0) {
								$adjustments[] = $adjustment;
							}
						}
					}
					if ($discount->baseDiscount !== null && $discount->baseDiscount != 0) {
						$baseDiscountAdjustment = $this->_createOrderAdjustment($discount);
						$baseDiscountAdjustment->amount = $discount->baseDiscount;
						$adjustments[] = $baseDiscountAdjustment;
					}
					$e->adjustments[$key]['amount'] = 
				}
			}
		});*/

		
		Craft::$app->view->hook('cp.commerce.product.edit.details', function(array &$context) {
			$view = Craft::$app->getView();
			//Craft::dd($context);
        	return $view->renderTemplate('commerce-currency-prices/prices', ['variants'=>$context['product']->variants]);
		});

		Craft::$app->view->hook('cp.commerce.bundle.edit.price', function(array &$context) {
			$view = Craft::$app->getView();
			//Craft::dd($context);
        	return $view->renderTemplate('commerce-currency-prices/prices-purchasable', ['purchasable'=>$context['bundle']]);
		});

		Craft::$app->view->hook('cp.digital-products.product.edit.details', function(array &$context) {
			$view = Craft::$app->getView();
			//Craft::dd($context);
        	return $view->renderTemplate('commerce-currency-prices/prices-purchasable', ['purchasable'=>$context['product']]);
		});

        Craft::info(
            Craft::t(
                'commerce-currency-prices',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

}
