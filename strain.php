<?php
/**
 * Обработчик данных. Анализирует данные используя специальную схему фильтрации.
 * Решает проблемы валидации и санитизации данных. Умеет приводить данные к нужному виду.
 * @copyright Copyright Yamanov Andrey (tenphi@gmail.com)
 * @link http://tenphi.com
 * @version 0.4
 */

class Strain {
	
/**
 * Ассоциативный массив схем (фильтров) $name => $scheme.
 * @var array
 * @access private
 */
	private static $_schemes = array();
	
/**
 * Объект результатов фильтрации.
 * @var array
 * @access public
 */
	public static $errors = array();
	
/**
 * Данные для валидации.
 * @var array
 * @access public
 */
	public static $data = array();
	
/**
 * Информация о валидности обработанных данных. TRUE / FALSE.
 * @var boolean
 * @access public
 */
	public static $valid = false;

	const NOCHANGE = 0;
	const COMPLETE = 1;
	const TRUNCATE = 2;
	const SANITIZE = 3;
	
/**
 * Запуск фильтрации.
 * @param mixed $data Объект для фильтрации.
 * @param mixed $filter Имя фильтра или схема фильтрации
 * @param integer $force Если:
 * 		self::NOCHANGE - Не меняет структуру данных;
 * 		self::COMPLETE - Добавляет в структуру данных свойства указанные в структуре фильтров;
 * 		self::TRUNCATE - Удаляет из структуры данных свойства не указанные в структуре фильтров;
 * 		self::SANITIZE - Действия COMPLETE и TRUNCATE вместе.
 * @return Объект результатов фильтрации повторяющий структуру проверяемых
 * 		данных.
 * @access private
 */
	private static function _filter(&$data, &$scheme, $force) 
	{
		if (is_callable($scheme)) {
			$return = $scheme($data);
		} else if (is_string($scheme)) {
			if (isset(self::$_schemes[$scheme])) {
				$return = self::_filter($data, self::$_schemes[$scheme], $force);
			} else {
				throw new Exception('Strain scheme `' . $scheme . '` not found.', E_USER_ERROR);
			};
		} else if (is_object($scheme)) {
			// Если проверяемые данные не являются объектом, то превращаем его
			// в пустой объект.
			if (!is_object($data)) {
				if ($force == 3) {
					$data = (object) array();
				} else {
					return true;
				}
			}
			// Возвращать в данном случае будем объект, поэтому создаём его.
			$return = (object) array();
			if ($force > 1) foreach ($data as $name => &$value) {
				// Если в проверяемом объекте есть свойста не указанные в фильтре,
				// то удаляем его.
				if (!isset($scheme->$name)) unset($data->$name);
			}
			foreach ($scheme as $name => &$fil) {
				// Если в проверяемом объекте нет свойста указанного в фильтре,
				// то создаем его.
				if (!isset($data->$name)) {
					if ($force % 2 == 1) {
						$data->$name = null;
					} else {
						continue;
					}
				}
				$return->$name = self::_filter($data->$name, $fil, $force);
			}
		} else
		// $scheme - массив/схема фильтрации
		if (is_array($scheme)) {
			foreach ($scheme as $key => &$sch) {
				if (is_int($key)) {
					if (self::my($data, $sch, $force)->valid) {
						$return = null;
					} else {
						$return = self::$errors;
					}
				} else {
					if (isset(self::$_schemes[$key])) {
						if (is_callable(self::$_schemes[$key])) {
							$filter = self::$_schemes[$key];
							$return = $filter($data, $sch);
						} else {
							$return = self::_filter($data, self::$_schemes[$key], $force);
						}
					} else {
						throw new Exception('Strain scheme `' . $key . '` not found.', E_USER_ERROR);
					}
				}
				if ($return !== null) break;
			}
		}
		return $return;
	}
	
/**
 * Метод инициирующий обработку данных. Аргументы аналогичны Strain::_filter().
 * @return Объект содержащий полную информация об обработке данных.
 * @access public
 */
	public static function my(&$data, &$scheme, $force) 
	{
		self::$data = $data;
		self::$errors = self::_filter($data, &$scheme, $force);
		self::$valid = !self::_bool(self::$errors);
		return (object) array(
			'errors' => self::$errors,
			'data' => $data,
			'valid' => self::$valid
		);
	}
	
/**
 * Анализирует объект результатов фильтрации.
 * @var obj Объект результатов фильтрации.
 * @return (boolean) - ДА, если были найдены ошибки, НЕТ - если не были.
 */
	private static function _bool($obj) 
	{
		if (is_object($obj)) {
			foreach ($obj as $fld) {
				$res = (is_object($fld) ? (self::_bool($fld) ? true : null) : $fld);
				if ($res !== null) 	return true;
			}
			return false;
		}
		return ($obj !== null ? true : false);
	}
	
/**
 * Враппер для метода Strain::my() с аргументом $force = 3.
 * @param mixed $data Обрабатываемые данные.
 * @param mixed $scheme Схема фильтрации.
 * @return Обработанные данные.
 * @access public
 */
	public static function sanitize(&$data, &$scheme) 
	{
		return self::my(&$data, &$scheme, 3)->data;
	}
	
/**
 * Враппер для метода Strain::my() с аргументом $force = 1.
 * @param mixed $data Обрабатываемые данные.
 * @param mixed $scheme Схема фильтрации.
 * @return Обработанные данные.
 * @access public
 */
	public static function complete(&$data, &$scheme) 
	{
		return self::my(&$data, &$scheme, 1)->data;
	}
	
/**
 * Враппер для метода Strain::my() с аргументом $force = 2.
 * @param mixed $data Обрабатываемые данные.
 * @param mixed $scheme Схема фильтрации.
 * @return Обработанные данные.
 * @access public
 */
	public static function truncate(&$data, &$scheme) 
	{
		self::my(&$data, &$scheme, 2)->data;
		return $data;
	}
	
/**
 * Добавление схемы фильтрации.
 * @var string $name Название схемы.
 * @var mixed $scheme Сама схема.
 * @return boolean TRUE, если схема добавлена. FALSE, если схема не добавлена.
 */
	public static function add($name, $scheme) 
	{
		if (is_string($name) && $name && (is_callable($scheme) || is_array($scheme) || is_object($scheme))) {
			self::$_schemes[$name] = $scheme;
			return true;
		} else {
			return false;
		}
	}
	
/**
 * Удаление схема фильтрации.
 * @param string $name Название схема.
 * @return boolean TRUE, если удаление прошло успешно. FALSE, если - нет.
 * @access public
 */
	public static function remove($name) 
	{
		if (is_string($name) && isset(self::$_filters[$name])) {
			unset(self::$_schemes[$name]);
			return true;
		}
		return false;
	}
	
}
