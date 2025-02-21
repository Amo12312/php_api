<?php
require 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Exception\Exception;

header('Content-Type: application/json');

$client = new Client("mongodb://localhost:27017");
$db = $client->iot_project;
$collection = $db->rack_wagons;
$settingsCollection = $db->settings;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['set_wagon_limit'], $data['rackId'])) {
        // Set wagon limit and rack ID
        $settingsCollection->updateOne([], ['$set' => [
            'wagon_limit' => (int) $data['set_wagon_limit'],
            'rackId' => $data['rackId']
        ]], ['upsert' => true]);

        echo json_encode(["message" => "Wagon limit set to " . $data['set_wagon_limit'], "rackId" => $data['rackId']]);
        exit;
    }

    if (!isset($data['rackId'])) {
        echo json_encode(["error" => "Missing required field: rackId"]);
        exit;
    }

    $settings = $settingsCollection->findOne();

    if (!isset($settings['wagon_limit']) || !isset($settings['rackId'])) {
        echo json_encode(["error" => "Operator has not set a wagon limit or rackId"]);
        exit;
    }

    if ($data['rackId'] !== $settings['rackId']) {
        echo json_encode(["error" => "Invalid rackId"]);
        exit;
    }

    $wagonLimit = (int) $settings['wagon_limit'];

    $rack = $collection->findOne(['rackId' => $data['rackId']]);

    if ($rack) {
        $wagonsArray = json_decode(json_encode($rack['wagons']), true); // Convert BSONArray to PHP array
        $totalWagons = count(array_filter($wagonsArray, fn($wagon) => $wagon['status'] !== "engine"));

        if ($totalWagons >= $wagonLimit) {
            echo json_encode(["error" => "Wagon limit reached, no more wagons allowed"]);
            exit;
        }
    } else {
        $rack = [
            '_id' => new ObjectId(),
            'rackId' => $data['rackId'],
            'wagons' => []
        ];
    }

    $wagonNo = count($rack['wagons']) + 1;

    // Ensure first wagon is always an engine
    $newWagon = [
        '_id' => new ObjectId(),
        'wagonNo' => $wagonNo,
        'status' => $wagonNo === 1 ? "engine" : ($data['status'] ?? "unknown"),
        'timestamp' => new MongoDB\BSON\UTCDateTime()
    ];

    $rack['wagons'][] = $newWagon;

    try {
        $collection->replaceOne(['rackId' => $data['rackId']], $rack, ['upsert' => true]);

        echo json_encode([
            "id" => (string) $rack['_id'],
            "rackId" => $rack['rackId'],
           "wagons" => array_map(function ($wagon) {
    return [
        "wagonNo" => $wagon['wagonNo'],
        "status" => $wagon['status'],
        "timestamp" => isset($wagon['timestamp']) && $wagon['timestamp'] instanceof MongoDB\BSON\UTCDateTime
            ? $wagon['timestamp']->toDateTime()->format('c')
            : date('c'), // Fallback to current time if timestamp is missing or invalid
        "_id" => (string) $wagon['_id']
    ];
}, iterator_to_array($rack['wagons'] ?? [])), // ✅ Fix: Convert BSONArray to PHP array
            "totalWagons" => count(array_filter(json_decode(json_encode($rack['wagons']), true), fn($wagon) => $wagon['status'] !== "engine")), // ✅ Fix: Exclude engine from count
            "message" => "Wagon " . $wagonNo . " data stored successfully."
        ]);
        
    } catch (Exception $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
}

if ($method === 'GET') {
    try {
        $rack = $collection->findOne();
        if (!$rack) {
            echo json_encode(["error" => "No records found"]);
            exit;
        }

        echo json_encode([
            "id" => (string) $rack['_id'],
            "rackId" => $rack['rackId'],
           "wagons" => array_map(function ($wagon) {
    return [
        "wagonNo" => $wagon['wagonNo'],
        "status" => $wagon['status'],
        "timestamp" => isset($wagon['timestamp']) && $wagon['timestamp'] instanceof MongoDB\BSON\UTCDateTime
            ? $wagon['timestamp']->toDateTime()->format('c')
            : date('c'), // Fallback to current time if timestamp is missing or invalid
        "_id" => (string) $wagon['_id']
    ];
}, iterator_to_array($rack['wagons'] ?? [])), // Ensure BSONArray is converted to PHP array

            //  json_decode(json_encode($rack['wagons']), true)), // ✅ Fix BSONArray issue
            "totalWagons" => count(array_filter(json_decode(json_encode($rack['wagons']), true), fn($wagon) => $wagon['status'] !== "engine")) // ✅ Fix: Exclude engine from count
        ]);
    } catch (Exception $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
}

if ($method === 'DELETE') {
    try {
        $collection->deleteMany([]);
        $settingsCollection->deleteMany([]);
        echo json_encode(["message" => "All records and settings deleted"]);
    } catch (Exception $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
}
?>
