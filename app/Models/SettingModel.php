<?php
// PATH LOKASI FILE: app/Models/SettingModel.php

class SettingModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function get($key, $default = null) {
        $stmt = $this->db->prepare("SELECT `value` FROM settings WHERE `key` = :k");
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public function set($key, $value, $updatedBy = null) {
        $stmt = $this->db->prepare("
            INSERT INTO settings (`key`, `value`, updated_by) VALUES (:k, :v, :by)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)
        ");
        $stmt->execute([':k' => $key, ':v' => $value, ':by' => $updatedBy]);
    }

    public function getMany(array $keys) {
        if (empty($keys)) return [];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ($placeholders)");
        $stmt->execute(array_values($keys));
        $result = [];
        foreach ($keys as $k) $result[$k] = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $result[$r['key']] = $r['value'];
        return $result;
    }

    public function setMany(array $pairs, $updatedBy = null) {
        $stmt = $this->db->prepare("
            INSERT INTO settings (`key`, `value`, updated_by) VALUES (:k, :v, :by)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)
        ");
        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v, ':by' => $updatedBy]);
        }
    }

    public function delete($key) {
        $this->db->prepare("DELETE FROM settings WHERE `key` = :k")->execute([':k' => $key]);
    }
}
