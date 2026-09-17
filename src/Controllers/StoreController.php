<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * E-commerce store — rider gear and consumables (see
 * planning/02-rider-co-ke/prd.md's store scope). Checkout reuses the
 * shared Payments Core; this controller only manages the catalog and
 * order records.
 */
final class StoreController
{
    public function listProducts(Request $request): void
    {
        $db = Database::connection();
        $category = $request->query['category'] ?? null;

        if ($category) {
            $stmt = $db->prepare('SELECT * FROM store_products WHERE status = \'active\' AND category = :category ORDER BY name');
            $stmt->execute(['category' => $category]);
        } else {
            $stmt = $db->query('SELECT * FROM store_products WHERE status = \'active\' ORDER BY name');
        }

        Response::json($stmt->fetchAll());
    }

    public function createOrder(Request $request): void
    {
        $db = Database::connection();
        $items = $request->input('items', []);

        if (empty($items)) {
            Response::error('Order must include at least one item', 422);
            return;
        }

        $db->beginTransaction();
        try {
            $total = 0;
            $priced = [];
            foreach ($items as $item) {
                $stmt = $db->prepare('SELECT id, price, stock_quantity FROM store_products WHERE id = :id FOR UPDATE');
                $stmt->execute(['id' => $item['product_id']]);
                $product = $stmt->fetch();

                if (!$product || $product['stock_quantity'] < $item['quantity']) {
                    throw new \RuntimeException("Product {$item['product_id']} is unavailable in the requested quantity");
                }

                $lineTotal = $product['price'] * $item['quantity'];
                $total += $lineTotal;
                $priced[] = ['product_id' => $product['id'], 'quantity' => $item['quantity'], 'unit_price' => $product['price']];
            }

            $stmt = $db->prepare(
                'INSERT INTO store_orders (customer_id, status, total_amount, delivery_address, created_at, updated_at)
                 VALUES (:customer_id, \'pending\', :total, :address, NOW(), NOW())'
            );
            $stmt->execute([
                'customer_id' => $request->user['id'] ?? null,
                'total' => $total,
                'address' => $request->input('delivery_address'),
            ]);
            $orderId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare(
                'INSERT INTO store_order_items (order_id, product_id, quantity, unit_price) VALUES (:order_id, :product_id, :quantity, :unit_price)'
            );
            $stockStmt = $db->prepare('UPDATE store_products SET stock_quantity = stock_quantity - :quantity WHERE id = :id');

            foreach ($priced as $line) {
                $itemStmt->execute(['order_id' => $orderId, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price']]);
                $stockStmt->execute(['quantity' => $line['quantity'], 'id' => $line['product_id']]);
            }

            $db->commit();
            Response::json(['id' => $orderId, 'total_amount' => $total, 'status' => 'pending'], 201);
        } catch (\RuntimeException $e) {
            $db->rollBack();
            Response::error($e->getMessage(), 422);
        }
    }
}
