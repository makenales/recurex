<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2015
 * @package Controller
 * @subpackage Common
 */


namespace Aimeos\Controller\Common\Product\Import\Csv\Processor\Product;


/**
 * Product processor for CSV imports
 *
 * @package Controller
 * @subpackage Common
 */
class Standard
	extends \Aimeos\Controller\Common\Product\Import\Csv\Processor\Base
	implements \Aimeos\Controller\Common\Product\Import\Csv\Processor\Iface
{
	/** controller/common/product/import/csv/processor/product/name
	 * Name of the product processor implementation
	 *
	 * Use "Myname" if your class is named "\Aimeos\Controller\Common\Product\Import\Csv\Processor\Product\Myname".
	 * The name is case-sensitive and you should avoid camel case names like "MyName".
	 *
	 * @param string Last part of the processor class name
	 * @since 2015.10
	 * @category Developer
	 */

	private $cache;
	private $listTypes;


	/**
	 * Initializes the object
	 *
	 * @param \Aimeos\MShop\Context\Item\Iface $context Context object
	 * @param array $mapping Associative list of field position in CSV as key and domain item key as value
	 * @param \Aimeos\Controller\Common\Product\Import\Csv\Processor\Iface $object Decorated processor
	 */
	public function __construct( \Aimeos\MShop\Context\Item\Iface $context, array $mapping,
		\Aimeos\Controller\Common\Product\Import\Csv\Processor\Iface $object = null )
	{
		parent::__construct( $context, $mapping, $object );

		/** controller/common/product/import/csv/processor/product/listtypes
		 * Names of the product list types that are updated or removed
		 *
		 * Aimeos offers associated items like "bought together" products that
		 * are automatically generated by other job controllers. These relations
		 * shouldn't normally be overwritten or deleted by default during the
		 * import and this confiuration option enables you to specify the list
		 * types that should be updated or removed if not available in the import
		 * file.
		 *
		 * Contrary, if you don't generate any relations automatically in the
		 * shop and want to import those relations too, you can set the option
		 * to null to update all associated items.
		 *
		 * @param array|null List of product list type names or null for all
		 * @since 2015.05
		 * @category Developer
		 * @category User
		 * @see controller/common/product/import/csv/domains
		 * @see controller/common/product/import/csv/processor/attribute/listtypes
		 * @see controller/common/product/import/csv/processor/catalog/listtypes
		 * @see controller/common/product/import/csv/processor/media/listtypes
		 * @see controller/common/product/import/csv/processor/price/listtypes
		 * @see controller/common/product/import/csv/processor/text/listtypes
		 */
		$default = array( 'default', 'suggestion' );
		$key = 'controller/common/product/import/csv/processor/product/listtypes';
		$this->listTypes = $context->getConfig()->get( $key, $default );

		$this->cache = $this->getCache( 'product' );
	}


	/**
	 * Saves the product related data to the storage
	 *
	 * @param \Aimeos\MShop\Product\Item\Iface $product Product item with associated items
	 * @param array $data List of CSV fields with position as key and data as value
	 * @return array List of data which hasn't been imported
	 */
	public function process( \Aimeos\MShop\Product\Item\Iface $product, array $data )
	{
		$context = $this->getContext();
		$manager = \Aimeos\MShop\Factory::createManager( $context, 'product' );
		$separator = $context->getConfig()->get( 'controller/common/product/import/csv/separator', "\n" );

		$this->cache->set( $product );

		$manager->begin();

		try
		{
			$types = array();

			foreach( $this->getMappedChunk( $data ) as $list )
			{
				if( !isset( $list['product.code'] ) || $list['product.code'] === '' || isset( $list['product.lists.type'] )
					&& $this->listTypes !== null && !in_array( $list['product.lists.type'], (array) $this->listTypes )
				) {
					continue;
				}

				$listMap = array();
				$type = ( isset( $list['product.lists.type'] ) ? $list['product.lists.type'] : 'default' );
				$types[] = $type;

				foreach( explode( $separator, $list['product.code'] ) as $code )
				{
					if( ( $prodid = $this->cache->get( $code ) ) === null )
					{
						$msg = 'No product for code "%1$s" available when importing product with code "%2$s"';
						throw new \Aimeos\Controller\Jobs\Exception( sprintf( $msg, $code, $product->getCode() ) );
					}

					$listMap[$prodid] = $list;
				}

				$manager->updateListItems( $product, $listMap, 'product', $type );
			}

			$this->deleteListItems( $product->getId(), $types );

			$remaining = $this->getObject()->process( $product, $data );

			$manager->commit();
		}
		catch( \Exception $e )
		{
			$manager->rollback();
			throw $e;
		}

		return $remaining;
	}


	/**
	 * Deletes all list items whose types aren't in the given list
	 *
	 * @param string $prodId Unique product ID
	 * @param array $types List of types that have been updated
	 */
	protected function deleteListItems( $prodId, array $types )
	{
		$codes = array_diff( $this->listTypes, $types );
		$manager = \Aimeos\MShop\Factory::createManager( $this->getContext(), 'product/lists' );

		$search = $manager->createSearch();
		$expr = array(
			$search->compare( '==', 'product.lists.parentid', $prodId ),
			$search->compare( '==', 'product.lists.domain', 'product' ),
			$search->compare( '==', 'product.lists.type.code', $codes ),
		);
		$search->setConditions( $search->combine( '&&', $expr ) );

		$manager->deleteItems( array_keys( $manager->searchItems( $search ) ) );
	}
}
