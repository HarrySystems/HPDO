<?php
    Class HPDO extends PDO
    {
        Public Function __construct(
            $conn,
            $specific = array()
        )
        {
            // array config
                if(is_array($conn))
                {
                    $settings = $conn;
                }
            // file config
                elseif(file_exists($file = __DIR__."/connections/".$conn.".json"))
                {
                    if (!$settings = json_decode(file_get_contents($file), true))
                        throw new exception("Invalid config file '" . $file . "'.");
                }
            // connection file not found
                else
                {
                    throw new exception("Connection file for '".$conn."' not found.");
                }

            // ovewrite params using "specific" array
                foreach((array)$specific as $key => $value)
                    $settings[$key] = $value;

            // connects to the databas
				switch(strtolower($settings['driver']))
				{
					case "mysql":
						parent::__construct(
							$settings['driver']
							.':host='.$settings['host']
							.(
									!empty($settings['port'])
								?   ';port='.$settings['port']
								:   ''
							)
							.';dbname='.$settings['schema'],
							$settings['username'],
							$settings['password'],
							array(
								PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
								PDO::ATTR_STATEMENT_CLASS => array('HPDOStatement')
							)
						);
						break;

					// TODO add possibility of other databases

					// case "oracle":
					// 	parent::__construct(
					// 		'oci:dbname=PR06',
					// 		'PCM_CON',
					// 		'DFGPCM'
					// 	);
					//
					// 	break;
				}

            $this->query("SET profiling = 1");

            register_shutdown_function(
                function($class)
                {

                    $profile = $class->query("show profiles")->fetchAssoc(true);
                    usort(
                        $profile,
                        function($a, $b)
                        {
                            return $b['Duration'] - $a['Duration'] ;
                        }
                    );

                    trigger_error(
                        print_r(
                            $profile,
                            true
                        )
                    );
                },
                $this
            );

        }

        // Basic query function
            Public Function query($query, $params = array())
            {
                // makes query execution traceable
                    $query = "\n/* ".$_SERVER['HTTP_HOST']." - ".$_SERVER['SCRIPT_NAME']." */\n".$query;

                if(!empty($params))
                {
                    $exec = parent::prepare($query);
                    $exec->execute($params);
                }
                else
                {
                    $exec = parent::query($query);
                }
                return $exec;
            }

        // Complementary functions
			// get info for filters
	            Public Function distinct($field, $table)
	            {
	                $exec = self::query(
	                    "
	                        SELECT ".$field."
	                        FROM ".$table."
	                        GROUP BY ".$field."
	                    "
	                );

	                return $exec;
	            }

			// transforms reference associative array into fk ids from database
				Public Function normalize(
					$data,
					$args
				)
				{
					foreach($args as $key => $arg)
					{
						// gets all $value => $key (inverse for usage as a referene)
							$ref = [];
							foreach(
								self::query(
									$query = "
										SELECT	DISTINCT
												`".$arg['field']."`,
												`".$arg['key']."`
										FROM	`".$arg['table']."`
										WHERE	1
												".(
														!empty($arg['where'])
													?	" AND ".$arg['where']
													:	""
												)."
									"
								)->fetchNum(true)
								as $value
							)
							{
								$ref[$value[0]] = $value[1];
							}

						// substitute data
							foreach($data as $row => &$col)
							{
								// rtrim necessario pois a base não salva espaço à direita
								$col[$key] = $ref[rtrim($col[$key])];
							}
					}
					return $data;
				}

				Public Function materialize(
					$table_name,
					$query,
					$indexes
				)
				{
					self::query(
						"
							DROP	TABLE
									IF EXISTS
									".$table_name."
						"
					);

					self::query(
						"
							CREATE	TABLE ".$table_name."
							AS 		".$query."

						"
					);

					foreach((array)$indexes as $index)
					{
						self::query(
							"
								ALTER	TABLE
										".$table_name."
								ADD		INDEX `".$index."` (`".$index."`)
							"
						);
					}


				}

			// smaller function for queries
	            Public Function q($query, $params = array())
	            {
	                self::query($query, $params);
	            }
    }

    Class HPDOStatement extends PDOStatement
    {
        Public Function fetchColumn($column = 0)
        {
            $exec = self::fetchNum(true);
            $aux = array();
            foreach($exec as $value)
                $aux[] = $value[$column];
            return $aux;
        }

        Public Function fetchNum($all = false)
        {
            if(parent::rowCount() == 0 || parent::rowCount() == null )// || !is_object(parent))
                // throw new exception("No data to be fetch!");
                return false;

            return parent::{"fetch".($all ? "All" : "")}(PDO::FETCH_NUM);
        }

        Public Function fetchAssoc($all = false)
        {
            if(parent::rowCount() == 0 || parent::rowCount() == null )// || !is_object(parent))
                // throw new exception("No data to be fetch!");
                return false;

            return parent::{"fetch".($all ? "All" : "")}(PDO::FETCH_ASSOC);
        }

        Public Function fetchObj($all = false)
        {
            if(parent::rowCount() == 0 || parent::rowCount() == null )// || !is_object(parent))
                // throw new exception("No data to be fetch!");
                return false;

            return parent::{"fetch".($all ? "All" : "")}(PDO::FETCH_OBJ);
        }

        Public Function fetchTable()
        {
            if(parent::rowCount() == 0 || parent::rowCount() == null )// || !is_object(parent))
                // throw new exception("No data to be fetch!");
                return false;

            $data = parent::fetchAll(PDO::FETCH_ASSOC);

            return array_merge(
    			array(array_keys($data[0])),
    			array_map(
    				function($value)
    				{
    					return array_values($value);
    				},
    				$data
    			)
    		);
        }

		Public Function fetchJSON()
		{
			return json_encode(
				$this->fetchAssoc(true),
				JSON_NUMERIC_CHECK
			);
		}

        // Public Function __call($method, $args)
        // {
        //     // não está funcionando. a idéia é centralizar todos os tipos fetchs nessa função
        //     // if(strpos($method, "fetch") == 0)
        //     // {
        //         // return  parent::{"fetch".($args['all'] ? "All" : "")}
        //         //         (constant("PDO::FETCH_".strtoupper(str_replace("fetch", "", $method))));
        //     // }
        //
        //     return true;
        // }
    }
    // Class HFilter
    // {
    //     const NOT_EMPTY = 1;
    //
    //     Public Function add(
    //         $prepare,
    //         $value,
    //         $condition)
    //     {
    //
    //     }
    //
    //     Public Function mount($params = array())
    //     {
    //
    //     }
    // }
