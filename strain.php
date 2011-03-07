<?php
/*
 * @class Strain - Фильтрация данных (Приведение и валидация)
 * @version 0.3
 * @copyright Tenphi - http://tenphi.com/
 * 
 * Класс производит декларативную фильтрацию данных. 
 * Для фильтрации данных используется схема (СФ), которая полностью повторяет 
 * структуру фильтруемого объекта, только вместо значений в нём используется
 * название фильтра или другие СФ.
 * 
 * Фильтр может выполнять две функции: приведение и валидация.
 * Фильтр-приведения преобразует входное значение в нужный тип данных или просто 
 * изменяет его согласно определенной логики.
 * 
 * Strain::add('integer', function(&$value, $options = null) {
 * 	$value = (int) $value;
 * });
 * 
 * Фильтр-валидации проверяет удовлетворяет ли данное какому-то условию и в случае 
 * неудачи возвращает true. (Здесь и далее по тексту TRUE также может являться любым
 * значением отличным от NULL и FALSE, Например, строкой с описанием возникшей ошибки).
 *
 * Strain::add('is_integer', function(&$value, $options = null) {
 * 	if(!is_int($value)) return true;
 * });
 * 
 * Если функция вернула true или false, то дальнейшая проверка данных 
 * не производится, но только true является свидетельством зафиксированной
 * ошибку, в то время как false означает всего лишь остановку. Это полезно,
 * если нужно, например, проверить является ли значение null'ом и только если нет - 
 * производить проверку. (Условие 'can be null')
 * 
 * Strain::add('null', function(&$value, $options) {
 * 	if ($value === null) return false;
 * });
 * 
 * Простой пример использования:
 * $text = 'Text';
 * Strain::it('Text', 'string'); // вернет false
 * 
 * Пример с опциями:
 * $text = 'Text';
 * Strain::it($text, array('string', 'length' => array(2,10);
 * 
 * Фильтр сам по себе может быть массивом имен фильтров: 
 * Strain::add('text', array('string', 'length' => array(2, 10)));
 * 
 * Пример с объектами:
 * $user = (object) array(
 * 		'email' => 'user@mail.com',
 * 		'name' => 'User',
 * 		'address' => (object) array(
 * 			'city' => 'Default City',
 * 			'street' => 'Big'
 * 		 )
 * );
 * $valid = (object) array(
 * 		'email' => ('email', 'UserExists'),
 * 		'name' => array('string', 'regexp' => '/^[A-Za-z0-9_-]{3,20}$/'),
 * 		'address' => (object) array(
 * 			'city' => 'string',
 * 			'street' => 'string'
 * 		)
 * );
 *
 * Strain::it($user, $valid);
 * 
 */

class Strain {
	
	// Ассоциативный массив фильтров $name => $filter.
	private static $_filters = array();
	
	// Доступ к объекту результатов после вызова Strain::it().
	public static $result = array();
	
	/* 
	 * Strain::filtering() - запуск фильтрации.
	 * @param $data (mixed) - объект для фильтрации.
	 * @param $filter (mixed) - имя фильтра или схема фильтрации
	 * $param $force (integer) - Если:
	 * 		0: Не меняет структуру данных;
	 * 		1: Добавляет в структуру данных свойства указанные в структуре фильтров;
	 * 		2: Удаляет из структуры данных свойства не указанные в структуре фильтров;
	 * 		3: 1 и 2 вместе. (По умолчанию)
	 * $return - Объект результатов фильтрации повторяющий структуру проверяемых
	 * 		данных.
	 */
	public static function filtering(&$data, $filter, $force = 3) {
		// Приводим к стандартному виду + рекурсивно извлекаем фильтры.
		self::parse($filter);
		// $filter - имя фильтра.
		if (is_string($filter)) {
			$func = self::$_filters[$filter];
			$return = $func($data);
			if ($return !== false) return $return;
		}
		// $filter - функция
		if (is_object($filter) && get_class($filter) == 'Closure') {
			$return = $filter($data);
			if ($return !== false) return $return;
		} else
		// $filter - объект/схема фильтрации
		if (is_object($filter)) {
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
			if ($force > 1) foreach ($data as $name => $value) {
				// Если в проверяемом объекте есть свойста не указанные в фильтре,
				// то удаляем его.
				if (!isset($filter->$name)) unset($data->$name);
			}
			foreach ($filter as $name => $fil) {
				// Если в проверяемом объекте нет свойста указанного в фильтре,
				// то создаем его.
				if (!isset($data->$name)) {
					if ($force % 2 == 1) {
						$data->$name = null;
					} else {
						continue;
					}
				}
				$return->$name = self::filtering($data->$name, $fil, $force);
			}
			return $return;
		}
		// $filter - массив/схема фильтрации
		if (is_array($filter)) {
			foreach ($filter as $name => $options) {
				$func = self::$_filters[$name];
				if (!is_callable($func)) {
					$return = self::filtering($data, $name, $force);
				} else {
					$return = $func($data, $options);
				}
				if ($return !== null) break;
			}
			if ($return !== false) return $return; 
		}
		return null;
	}
	
	/*
	 * Strain::it() - аналогично Strain::filtering(), но возвращает просто true/false.
	 * Записывает результат фильтрации в Strain::$result.
	 * @return (bool)
	 */
	public static function it(&$data, $filter, $force = 3) {
		self::$result = self::filtering($data, $filter, $force);
		return self::bool(self::$result);
	}
	
	/*
	 * Strain::bool() - анализирует полученный объект, и если хоть одно из его свойств
	 * или подсвойств не равно null, то возвращает true, иначе - false.
	 */
	public static function bool($obj) {
		if (is_object($obj)) {
			foreach ($obj as $fld) {
				$res = is_object($fld) ? self::bool($fld) : $fld;
				if ($res) return true;
			}
		} else {
			if ($obj) return true;
		}
		return false;
	}
	
	/* Strain::get() - получение фильтра по имени.
	 * @param $name (string) - имя фильтра. Если не задан, возвращается 
	 * 		массив из всех фильтров.
	 * @return (mixed) - фильтр или массив фильтров.
	 */
	public static function get($name = null) {
		if (is_string($name) && isset(self::$_filters[$name])) return self::$_filters[$name];
		if ($name === null) return self::$_filters;
	}
	
	/* Strain::add() - добавление фильтра.
	 * @param $name (string) - имя фильтра.
	 * @param $filter (mixed) - фильтр.
	 * @return - true, если фильтр добавлен, false - если нет.
	 */
	public static function add($name, $filter) {
		if (is_string($name) && $name && (is_callable($filter) || is_array($filter) || is_object($filter))) {
			self::$_filters[$name] = $filter;
			return true;
		} else {
			return false;
		}
	}
	
	/* Strain::remove() - удаление фильтра по имени.
	 * @param $name (string) - имя фильтра.
	 * return (bool) - true, если удаление прошло успешно, false - если нет.
	 */
	public static function remove($name) {
		if (is_string($name) && isset(self::$_filters[$name])) {
			unset(self::$_filters[$name]);
			return true;
		}
		return false;
	}
	
	/* Strain::parse() - приведение фильтра к стандартному виду, рекурсивное 
	 * 		извление фильтров.
	 * @param $filter (mixed) - фильтр.
	 * @return null
	 */
	public static function parse(&$filter) {
		// $filter - имя фильтра
		if (is_string($filter)) {
			if (isset(self::$_filters[$filter])) {
				if (!is_callable(self::$_filters[$filter])) {
					$filter = self::$_filters[$filter];
					self::parse($filter);
				}
				return;
			} else {
				trigger_error('Filter `' . $filter . '` not found.', E_USER_WARNING);
			}
		};
		// $filter - объект содержащий фильтры для свойств фильтруемого объекта
		if (is_object($filter) && !is_callable($filter)) {
			foreach ($filter as $key => &$value) {
				self::parse($value);
			}
		}
		// $filter - массив фильтров
		if (is_array($filter)) {
			$keys = array_keys($filter);
			$newfilter = array();
			for ($i = 0; $i < count($keys); $i++) {
				$key = $keys[$i];
				$value = &$filter[$key];
				if (is_int($key) && is_string($value)) {
					if (isset(self::$_filters[$value])) {
						$link = &self::$_filters[$value];
						if (is_callable($link)) {
							$newfilter[$value] = null;
						} else {
							$newfilter[$value] = $link;
						}
						self::parse($newfilter[$value]);
						unset($filter[$key]);
					}
				} else {
					$newfilter[$key] = &$value;
				}
			}
			$filter = $newfilter;
		};
		return null;
	}
	
}

Strain::add('array_of', function(&$value, $options) {
	if (!is_array($value)) {
		$value = array();
		return true;
	}
	foreach($value as &$val) {
		Strain::it($val, $options);
		$res = Strain::$result;
		if (is_object($res)) {
			if (self::bool($res)) return $bool;
		} else if ($res !== null && $res !== false) return true;
	}
});

Strain::add('mixed', function(&$value, $options = null) {
	$flag = true;
	if ($options && is_array($options)) {
		foreach ($options as $key => $valid) {
			if (is_string($key)) $valid = array($key => $valid);
			if (!self::it($value, $valid)) $flag = null; // Одно из условий выполнилось
		}
	}
	return $flag;
});

Strain::add('string', function(&$value, $options =null) {
	$value = (string) $value;
	$length = strlen($value);
	if ($options && is_int($options) && $length > $options) {
		$value = substr($value, 0, $options);
	}
});
