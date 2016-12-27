<?php
namespace lib\domain;

abstract  class PersistentEntity extends \Entity implements PersistentInterface {
    
    /**
     * Identifier attribute
     * 
     * @var string
     */
    const ID = 'id';
    
    /**
     * Local institution database type
     * 
     * @var string
     */
    const TYPE_INST = 'INST';
    
    /**
     * External user database type
     * 
     * @var string
     */
    const TYPE_USER = 'USER';
    
    /**
     * 
     * @var \DBConnection
     */
    protected $databaseHandle;
    
    /**
     * Database table name
     * 
     * @var string
     */
    protected $table = "";
    
    /**
     * Record row from database
     * 
     * @var Attribute[]
     */
    private $row = array();
    
    /**
     * 
     * @var string[]
     */
    private $types = array();
    
    /**
     * Defines table and creates database handle
     * 
     * @param string $table
     * @param string $databaseType
     */
    public function __construct($table, $databaseType = 'INST'){
        $this->table = $table;
        $this->setAttributeType(self::ID, Attribute::TYPE_INTEGER);
        $this->databaseHandle = \DBConnection::handle($databaseType);
    }
    
    /**
     * @todo Need to implement attribute type handling for database
     */
    protected abstract function validate();
    
    /**
     * 
     * @param string $key
     * @param string $type
     */
    protected function setAttributeType($key, $type){
        $this->types[$key] = $type; 
    }
    
    /**
     * 
     * @param string $key
     * @return string
     */
    protected function getAttributeType($key){
        return isset($this->types[$key]) ? $this->types[$key] : Attribute::TYPE_STRING;
    }
    
    /**
     * Retrieves attribute value from record
     * 
     * @param string $key
     * @return string
     */
    public function get($key){
        return isset($this->row[$key]) ? $this->row[$key]->value : "";
    }
    
    /**
     * 
     * @param string $key
     * @return NULL|\lib\domain\Attribute
     */
    public function getAttribute($key){
        return isset($this->row[$key]) ? $this->row[$key] : new Attribute('', '');
    }
    
    /**
     * Sets attribute value
     * 
     * @param string $key
     * @param mixed $value
     */
    protected function set($key, $value){
        $attribute = new Attribute($key, $value, $this->getAttributeType($key));
        $this->row[$key] = $attribute;
    }
    
    /**
     * 
     * @param array $row
     */
    public function setRow($row){
        $this->clear();
        foreach ($row as $key => $value){
            $this->set($key, $value);
        }
    }
    
    /**
     * 
     */
    public function clear(){
        $this->row = array();
    }
    
    /**
     * 
     * @return string
     */
    public function getIdentifier(){
        return $this->get(self::ID);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \lib\domain\Persistent::save()
     */
    public function save(){
        $result = false;
        if(count($this->row) > 0){
            if(isset($this->row[self::ID])){
                $result = $this->executeUpdateQuery();
            }else{
                $result = $this->executeInsertQuery();
            }
        }
        return $result;
    }
    
    /**
     * 
     * @return boolean|mixed
     */
    private function executeInsertQuery(){
        $result = false;
        $query = "INSERT INTO `".$this->table."`";
        $keyString = "(";
        $valueString = "(";
        $types = '';
        $arguments = array();
        foreach ($this->row as $key => $attribute) {
            if($keyString != "(") $keyString .= " ,";
            if($valueString != "(") $valueString .= " ,";
            $keyString .= "`" . $key . "`";
            $valueString .= "?";
            $types .= $attribute->getType();
            $arguments [] = $attribute->value;
        }
        $keyString .= ")";
        $valueString .= ")";
        if($keyString != "()"){
            $query .= " " .$keyString . " VALUES " . $valueString;
            $result = $this->databaseHandle->exec($query, $types, ...$arguments);
            if($result){
                $this->set(self::ID, $this->databaseHandle->lastID());
            }
        }
        return $result;
    }
    
    /**
     * 
     * @return boolean|mixed
     */
    private function executeUpdateQuery(){
        $result = false;
        $query = "UPDATE `".$this->table."`";
        $updateString = "";
        $types = '';
        $arguments = array();
        foreach ($this->row as $key => $attribute) {
            if(!empty($updateString)) $updateString .= " ,";
            else $updateString .=" SET ";
            $updateString .= "`" . $key . "`=?";
            $types .= $attribute->getType();
            $arguments [] = $attribute->value;
        }
        if(!empty($updateString)){
            $query .= " " .$updateString . " WHERE `" .self::ID. "`=?";
            $id = $this->getAttribute(self::ID);
            $types .= $id->getType();
            $arguments [] = $id->value;
            $result = $this->databaseHandle->exec($query, $types, ...$arguments);
        }
        return $result;
    }
    /**
     * 
     * {@inheritDoc}
     * @see \lib\domain\Persistent::load()
     */
    public function load(){
        $state = false;
        $id = $this->getAttribute(self::ID);
        $result = $this->databaseHandle->exec("SELECT * FROM `".$this->table."` WHERE `".self::ID."` =?", $id->getType(), $id->value);
        if(mysqli_num_rows($result)>0){
            $this->setRow(mysqli_fetch_assoc($result));
            $state = true;
        }
        return $state;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \lib\domain\Persistent::delete()
     */
    public function delete(){
        $id = $this->getAttribute(self::ID);
        return $this->databaseHandle->exec("DELETE FROM `" . $this->table . "` WHERE `".self::ID."`=?", $id->getType(), $id->value);
    }
    
}
