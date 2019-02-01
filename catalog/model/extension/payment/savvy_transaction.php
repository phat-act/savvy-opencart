<?php

class ModelExtensionPaymentSavvyTransaction extends Model
{
    public function findByHash($hash)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "savvy_transaction WHERE transaction_hash = '" . $hash . "'");

        return $query->row;
    }

    public function insert($data)
    {
        $now = date('Y-m-d H:i:s');
        if (!isset($data['date_added'])) {
            $data['date_added'] = $now;
        }

        if (!isset($data['date_modified'])) {
            $data['date_modified'] = $now;
        }

        $valuesStrings = [];
        foreach ($data as $field => $value) {
            $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
        }

        $this->db->query("INSERT INTO " . DB_PREFIX . "savvy_transaction SET " . implode(', ', $valuesStrings));
    }

    public function update($id, $data) {
        if (!isset($data['date_modified'])) {
            $data['date_modified'] = date('Y-m-d H:i:s');
        }

        if (isset($data['savvy_transaction_id'])) {
            unset($data['savvy_transaction_id']);
        }

        $valuesStrings = [];
        foreach ($data as $field => $value) {
            $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
        }

        $this->db->query("UPDATE " . DB_PREFIX . "savvy_transaction SET " . implode(', ', $valuesStrings) . ' WHERE savvy_transaction_id = ' . (int) $id);
    }
}
