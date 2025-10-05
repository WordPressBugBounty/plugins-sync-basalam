<?php
class SyncBasalamJobManager
{
    private static $instance = null;
    private $job_manager_table_name;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    function __construct()
    {
        global $wpdb;
        $this->job_manager_table_name = $wpdb->prefix . 'sync_basalam_job_manager';
    }

    
    
    
    
    

    public function create_job($job_type, $status = 'pending', $payload = null)
    {
        global $wpdb;

        

        
        
        

        
        
        
        
        

        
        
        

        $wpdb->insert(
            $this->job_manager_table_name,
            array(
                'job_type'      => $job_type,
                'status'        => $status,
                'payload'       => $payload,
                'created_at'    => time(),
            )
        );

        return $wpdb;
    }

    
    
    
    

    public function get_job($where = array())
    {
        global $wpdb;

        if (empty($where)) {
            return null;
        }

        $conditions = [];
        $values     = [];

        foreach ($where as $column => $value) {
            $conditions[] = "{$column} = %s";
            $values[]     = $value;
        }

        $sql = "SELECT * FROM {$this->job_manager_table_name} WHERE " . implode(" AND ", $conditions) . " LIMIT 1";

        return $wpdb->get_row($wpdb->prepare($sql, $values));
    }

    public function get_count_jobs($where = array())
    {
        global $wpdb;

        if (empty($where)) {
            return 0;
        }

        $conditions = [];
        $values     = [];

        foreach ($where as $column => $value) {
            if (is_array($value)) {
                
                $placeholders = array_fill(0, count($value), '%s');
                $conditions[] = "{$column} IN (" . implode(',', $placeholders) . ")";
                $values = array_merge($values, $value);
            } else {
                $conditions[] = "{$column} = %s";
                $values[]     = $value;
            }
        }

        $sql = "SELECT COUNT(*) FROM {$this->job_manager_table_name} WHERE " . implode(" AND ", $conditions);

        return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
    }

    
    
    
    
    
    
    
    
    
    

    public function update_job($job_data, $where = array())
    {
        global $wpdb;

        if (empty($where) || empty($job_data)) {
            return false;
        }

        return $wpdb->update($this->job_manager_table_name, $job_data, $where);
    }

    
    
    
    

    public function delete_job($where = array())
    {
        global $wpdb;

        if (empty($where)) {
            return false;
        }

        return $wpdb->delete($this->job_manager_table_name, $where);
    }
}
