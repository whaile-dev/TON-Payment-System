<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');

function getColdWalletConfig($conn) {
    $default = (getConfig())['cold_wallet'] ?? [
        'enabled' => true,
        'address' => '',
        'label' => 'SafePal S1',
        'large_withdraw_threshold_ton' => 1000.0
    ];
    $conn->query("CREATE TABLE IF NOT EXISTS ColdWalletSettings (
        id INT PRIMARY KEY DEFAULT 1,
        enabled TINYINT NOT NULL DEFAULT 1,
        address VARCHAR(100) NOT NULL DEFAULT '',
        label VARCHAR(100) NOT NULL DEFAULT 'SafePal S1',
        threshold_ton DECIMAL(10,2) NOT NULL DEFAULT 1000,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $r = $conn->query("SELECT enabled, address, label, threshold_ton FROM ColdWalletSettings WHERE id = 1");
    if ($r && $row = $r->fetch_assoc()) {
        return [
            'enabled' => (int)$row['enabled'] !== 0,
            'address' => (string)$row['address'],
            'label' => (string)$row['label'],
            'large_withdraw_threshold_ton' => (float)$row['threshold_ton']
        ];
    }
    $en = (int)(!empty($default['enabled']));
    $addr = $default['address'] ?? '';
    $lbl = $default['label'] ?? 'SafePal S1';
    $thr = (float)($default['large_withdraw_threshold_ton'] ?? 1000);
    $st = $conn->prepare("INSERT INTO ColdWalletSettings (id, enabled, address, label, threshold_ton) VALUES (1, ?, ?, ?, ?)");
    $st->bind_param("issd", $en, $addr, $lbl, $thr);
    $st->execute();
    $st->close();
    return [
        'enabled' => (bool)$en,
        'address' => $default['address'] ?? '',
        'label' => $default['label'] ?? 'SafePal S1',
        'large_withdraw_threshold_ton' => $thr
    ];
}
