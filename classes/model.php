<?php
/**
 * ExiteCMS
 *
 * ExiteCMS is a web application framework,
 * based on the Fuel PHP development framework
 *
 * @package    Themes
 * @version    1.0
 * @author     ExiteCMS Development Team
 * @license	   Creative Commons BY-NC-ND-3.0
 * @copyright  2011 ExiteCMS Development Team
 * @link       http://www.exitecms.org
 */

namespace Nestedsets;

/*
 * Make sure the ORM package is loaded
 */
\Fuel::add_package('orm');

/**
 * Model class.
 *
 * @package nestedsets
 */
class Model extends \Orm\Model {

	/* ---------------------------------------------------------------------------
	 * Static usage
	 * --------------------------------------------------------------------------- */

	/*
	 * @var	default nestedset tree configuration
	 */
	protected static $defaults = array(
		'left_field'     => 'left_id',		// name of the tree node left index field
		'right_field'    => 'right_id',		// name of the tree node right index field
		'tree_field'     => null,			// name of the tree node tree index field
		'tree_value'     => null,			// value of the selected tree index
		'title_field'    => null,			// value of the tree node title field
		'symlink_field'  => 'symlink_id',	// name of the tree node tree index field
		'use_symlinks'   => false,			// use tree symlinks?
	);

	/* ---------------------------------------------------------------------------
	 * Dynamic usage
	 * --------------------------------------------------------------------------- */

	/**
	 * tree configuration for this instance
	 *
	 * @var    array
	 */
	private $configuration = array(
	);

	/**
	 * readonly fields for this model
	 *
	 * @var    array
	 */
	private $readonly_fields = array(
	);

	// -------------------------------------------------------------------------

	/*
	 * initialize the nestedset model instance
	 *
	 * @param    array
	 * @param    bool
	 */
	public function __construct(array $data = array(), $new = true)
	{
		// call the ORM model constructor
		parent::__construct($data, $new);

		// process the model's tree properties, set some defaults if needed
		if (isset(static::$tree) and is_array(static::$tree))
		{
			foreach(static::$defaults as $key => $value)
			{
				$this->configuration[$key] = isset(static::$tree[$key]) ? static::$tree[$key] : static::$defaults[$key];
			}
		}
		else
		{
			$this->configuration = array_merge(static::$defaults, $this->configuration);
		}

		// array of read-only column names
		foreach(array('left_field','right_field','tree_field','symlink_field') as $field)
		{
			! empty($this->configuration[$field]) and $this->readonly_fields[] = $this->configuration[$field];
		}

		if (count(static::$_primary_key) > 1)
		{
			throw new \Exception('The NestedSets doesn\'t support ORM Models with multiple primary key columns.');
		}
	}

	/* -------------------------------------------------------------------------
	 * tree properties
	 * ---------------------------------------------------------------------- */

	/**
	 * Set a tree property
	 *
	 * @param  string
	 * @param  mixed
	 */
	public function tree_set_property($name, $value)
	{
		array_key_exists($name, $this->configuration) and $this->configuration[$name] = $value;
	}

	/**
	 * Get a tree property
	 *
	 * @param  string
	 * @param  mixed
	 */
	public  function tree_get_property($name)
	{
		return array_key_exists($name, $this->configuration) ? $this->configuration[$name] :  null;
	}

	/* -------------------------------------------------------------------------
	 * multi-tree select
	 * ---------------------------------------------------------------------- */

	/**
	 * Select a specific tree if the table contains multiple trees
	 *
	 * @param   mixed	type depends on the field type of the tree_field
	 * @return  object	this object, for chaining
	 */
	public function tree_select($tree = null)
	{
		// set the filter value
		$this->tree_set_property('tree_value', $tree);

		// return the object for chaining
		return $this;
	}

	/* -------------------------------------------------------------------------
	 * tree constructors
	 * ---------------------------------------------------------------------- */

	/**
	 * Creates a new root node
	 *
	 * @return	object	Nestedsets\Model
	 */
	public function tree_new_root()
	{
		if ( ! $this->is_new())
		{
			throw new \Exception('You can only use new_root() on a new model object.');
		}

		// set the left- and right pointers for the new root
		$this->{$this->configuration['left_field']} = 1;
		$this->{$this->configuration['right_field']} = 2;

		// multi-root tree?
		if ( ! is_null($this->configuration['tree_field']))
		{
			// insert the new object with a unique tree id
			$new_tree = $this->max($this->configuration['tree_field']) + 1;
			$max_errors = 5;
			while (true)
			{
				$this->{$this->configuration['tree_field']} = $new_tree++;

				// clumsy hack to hopefully capture a duplicate key error
				try
				{
					$this->save();
					break;
				}
				catch (\Exception $e)
				{
					// if we have more errors, it's likely not to be a duplicate key...
					if (--$max_errors == 0)
					{
						throw $e;
					}
				}
			}
		}
		else
		{
			$this->save();
		}

		// return the ORM Model object
		return $this;
	}

	/* -------------------------------------------------------------------------
	 * tree queries
	 * ---------------------------------------------------------------------- */

	/**
	 * Returns the root of the (selected) tree
	 *
	 * @return	object	Nestedsets\Model
	 */
	public function tree_get_root()
	{
		$query = static::find()->where($this->configuration['left_field'], 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
		}

		// return the ORM Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the parent of the 'object' passed
	 *
	 * @param	object	Nestedsets\Model
	 * @return	object	Nestedsets\Model
	 */
	public function tree_get_parent(\Nestedsets\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = static::find()
			->where($this->configuration['left_field'], '<', $object->{$this->configuration['left_field']})
			->where($this->configuration['right_field'], '>', $object->{$this->configuration['right_field']})
			->order_by($this->configuration['right_field'], 'ASC');

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
		}

		// return the Nestedsets Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the first child of the 'object' passed
	 *
	 * @param	object	Nestedsets\Model
	 * @return	object	Nestedsets\Model
	 */
	public function tree_get_first_child(\Nestedsets\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = static::find()
			->where($this->configuration['left_field'], $object->{$this->configuration['left_field']} + 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
		}

		// return the Nestedsets Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the last child of the 'object' passed
	 *
	 * @param   object Nestedsets\Model
	 * @return	object	Nestedsets\Model
	 */
	public function tree_get_last_child(\Nestedsets\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = static::find()
			->where($this->configuration['right_field'], $object->{$this->configuration['right_field']} - 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
		}

		// return the Nestedsets Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the previous sibling of the 'object' passed
	 *
	 * @param   object Nestedsets\Model
	 * @return	object	Nestedsets\Model
	 */
	public function tree_get_previous_sibling(\Nestedsets\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = static::find()
			->where($this->configuration['right_field'], $object->{$this->configuration['left_field']} - 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
		}

		// return the Nestedsets Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the next sibling of the 'object' passed
	 *
	 * @param   object Nestedsets\Model
	 * @return	object	Nestedsets\Model
	 */
	public function tree_get_next_sibling(\Nestedsets\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = static::find()
			->where($this->configuration['left_field'], $object->{$this->configuration['right_field']} + 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
		}

		// return the Nestedsets Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------
	// Boolean tree functions
	// -----------------------------------------------------------------

	/**
	 * Check if the object is a valid tree node
	 *
	 * @return  bool
	 */
	public function tree_is_valid()
	{
		if ( $this->is_new() )
		{
			return false;
		}
		elseif ( ! isset($this->{$this->configuration['left_field']}) or ! is_numeric($this->{$this->configuration['left_field']}) or $this->{$this->configuration['left_field']} <= 0 )
		{
			return false;
		}
		elseif ( ! isset($this->{$this->configuration['right_field']}) or ! is_numeric($this->{$this->configuration['right_field']}) or $this->{$this->configuration['right_field']} <= 0 )
		{
			return false;
		}
		elseif ( $this->{$this->configuration['left_field']} >= $this->{$this->configuration['right_field']} )
		{
			return false;
		}
		elseif ( ! is_null($this->configuration['tree_field']) and ! isset($this->{$this->configuration['tree_field']}) )
		{
			return false;
		}
		elseif ( ! is_null($this->configuration['tree_field']) and ( ! is_numeric($this->{$this->configuration['tree_field']}) or $this->{$this->configuration['tree_field']} <=0  ) )
		{
			return false;
		}

		// all looks well...
		return true;
	}

	// -----------------------------------------------------------------

	/**
	 * Check if the object is a tree root
	 *
	 * @return  bool
	 */
	public function tree_is_root()
	{
		return $this->tree_is_valid($this) and $this->{$this->configuration['left_field']} == 1;
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object is a tree leaf (node with no children)
	 *
	 * @return  bool
	 */
	public function tree_is_leaf()
	{
		return $this->tree_is_valid($this) and $this->{$this->configuration['right_field']} - $this->{$this->configuration['left_field']} == 1;
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object is a child node (not a root node)
	 *
	 * @return  bool
	 */
	public function tree_is_child()
	{
		return $this->tree_is_valid($this) and ! $this->is_root($this);
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object is a child of node
	 *
	 * @param   object Nestedsets\Model of the parent to check
	 * @return  bool
	 */
	public function tree_is_child_of(\Nestedsets\Model $parent)
	{
		return $this->tree_is_valid($this) and
			$this->tree_is_valid($parent) and
			$this->{$this->configuration['left_field']} > $parent->{$this->configuration['left_field']} and
			$this->{$this->configuration['right_field']} < $parent->{$this->configuration['right_field']};
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object is a parent of node
	 *
	 * @param   object Nestedsets\Model of the child to check
	 * @return  bool
	 */
	public function tree_is_parent_of(\Nestedsets\Model $child)
	{
		return $this->tree_is_valid($child) and
			$this->tree_is_valid($this) and
			$child->{$this->configuration['left_field']} > $this->{$this->configuration['left_field']} and
			$child->{$this->configuration['right_field']} < $this->{$this->configuration['right_field']};
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object has a parent
	 *
	 * Note: this is an alias for is_child()
	 *
	 * @return  bool
	 */
	public function tree_has_parent()
	{
		return $this->is_child($this);
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object has children
	 *
	 * @return  bool
	 */
	public function tree_has_children()
	{
		return $this->is_leaf($this) ? false : true;
	}

	// -----------------------------------------------------------------

	/**
	 * Check if the object has a previous sibling
	 *
	 * @return  bool
	 */
	public function tree_has_previous_sibling()
	{
		return ! is_null($this->get_previous_sibling($this));
	}

	// -----------------------------------------------------------------

	/**
	 * Check if the object has a next sibling
	 *
	 * @return  bool
	 */
	public function tree_has_next_sibling()
	{
		return ! is_null($this->get_next_sibling($this));
	}

	// -----------------------------------------------------------------
	// Integer tree functions
	// -----------------------------------------------------------------

	/**
	 * return the count of the objects children
	 *
	 * @return	mixed	integer, of false in case no valid object was passed
	 */
	public function tree_count_children()
	{
		return $this->tree_is_valid($this) ? (($this->{$this->configuration['right_field']} - $this->{$this->configuration['left_field']} - 1) / 2) : false;
	}

	// -----------------------------------------------------------------

	/**
	 * return the depth of the object in the tree, where the root = 0
	 *
	 * @return	mixed	integer, of false in case no valid object was passed
	 */
	public function tree_depth()
	{
		if ($this->tree_is_valid($this))
		{
			$query = static::find();

			if ( ! is_null($this->configuration['tree_field']))
			{
				$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
			}

			$query->where($this->configuration['left_field'], '<', $object->{$this->configuration['left_field']});
			$query->where($this->configuration['right_field'], '>', $object->{$this->configuration['right_field']});

			// return the Nestedsets Model result count
			return $query->count();
		}
		else
		{
			return false;
		}
	}

	/* -------------------------------------------------------------------------
	 * tree reorganisation functions
	 * ---------------------------------------------------------------------- */

	/**
	 * move $object to next silbling of $to
	 *
	 * @param   object Nestedsets\Model
	 * @return  bool
	 */
	public function tree_make_next_sibling_of(\Nestedsets\Model $to)
	{
		$this->validate_model($to, __METHOD__);

		if ($this->tree_is_valid($this) and $this->tree_is_valid($to))
		{
			if ( ! is_null($this->configuration['tree_field']))
			{
				if ($this->{$this->configuration['tree_field']} !== $to->{$this->configuration['tree_field']})
				{
					throw new \Exception('When moving nodes, nodes must be part of the same tree.');
				}
			}

			return $this->_tree_move_subtree($to->{$this->configuration['right_field']} + 1);
		}
		else
		{
			return false;
		}
	}

	// -----------------------------------------------------------------

	/**
	 * move $object to previous silbling of $to
	 *
	 * @param   object Nestedsets\Model
	 * @return  bool
	 */
	public function tree_make_previous_sibling_of(\Nestedsets\Model $to)
	{
		$this->validate_model($to, __METHOD__);

		if ($this->tree_is_valid($this) and $this->tree_is_valid($to))
		{
			if ( ! is_null($this->configuration['tree_field']))
			{
				if ($this->{$this->configuration['tree_field']} !== $to->{$this->configuration['tree_field']})
				{
					throw new \Exception('When moving nodes, nodes must be part of the same tree.');
				}
			}

			return $this->_tree_move_subtree($to->{$this->configuration['left_field']});
		}
		else
		{
			return false;
		}
	}

	// -----------------------------------------------------------------

	/**
	 * move $object to first child of $to
	 *
	 * @param   object Nestedsets\Model
	 * @return  bool
	 */
	public function tree_make_first_child_of(\Nestedsets\Model $to)
	{
		$this->validate_model($to, __METHOD__);

		if ($this->tree_is_valid($this) and $this->tree_is_valid($to))
		{
			if ( ! is_null($this->configuration['tree_field']))
			{
				if ($this->{$this->configuration['tree_field']} !== $to->{$this->configuration['tree_field']})
				{
					throw new \Exception('When moving nodes, nodes must be part of the same tree.');
				}
			}

			return $this->_tree_move_subtree($to->{$this->configuration['left_field']} + 1);
		}
		else
		{
			return false;
		}
	}

	// -----------------------------------------------------------------

	/**
	 * move $object to last child of $to
	 *
	 * @param   object Nestedsets\Model
	 * @return  bool
	 */
	public function tree_make_last_child_of(\Nestedsets\Model $to)
	{
		$this->validate_model($to, __METHOD__);

		if ($this->tree_is_valid($this) and $this->tree_is_valid($to))
		{
			if ( ! is_null($this->configuration['tree_field']))
			{
				if ($this->{$this->configuration['tree_field']} !== $to->{$this->configuration['tree_field']})
				{
					throw new \Exception('When moving nodes, nodes must be part of the same tree.');
				}
			}

			return $this->_tree_move_subtree($to->{$this->configuration['right_field']});
		}
		else
		{
			return false;
		}
	}

	/* -------------------------------------------------------------------------
	 * tree destructors
	 * ---------------------------------------------------------------------- */

	/**
	 * deletes the entire tree structure including all records
	 *
	 * @param	boolean	if true, delete all trees
	 * @return	bool
	 */
	public function tree_delete_tree($all = false)
	{
		$query = static::find();

		// if we have multiple roots
		if ( ! is_null($this->configuration['tree_field']))
		{
			// by default, only delete the current tree
			$all === true or $query->where($this->configuration['tree_field'], $this->{$this->configuration['tree_field']});
		}

		return $query->delete();
	}

	// -----------------------------------------------------------------

	/**
	 * deletes the current tree node
	 *
	 * @return	bool
	 */
	public function tree_delete()
	{
		if ($this->tree_is_valid($this))
		{
			// re-index the tree
			$this->_tree_shift_rlvalues($this->{$this->configuration['right_field']} + 1, $this->{$this->configuration['left_field']} - $this->{$this->configuration['right_field']} - 1);

			// delete the node
			$this->delete();
		}
		else
		{
			return false;
		}

		return true;
	}

	/* -------------------------------------------------------------------------
	 * tree dump functions
	 * ---------------------------------------------------------------------- */

	/**
	 * returns the tree in a key-value format suitable for html dropdowns
	 *
	 */
	public function tree_dump_dropdown($field = null, $skip_root = false)
	{
		// set the name field
		empty($field) and $field = $this->configuration['title_field'];

		// we need a name field to generate the tree
		if ( ! is_null($field))
		{
			// fetch the tree into an array
			$result = $this->_tree_dump_as('array', array($field), $skip_root);

			// storage for the dropdown tree
			$tree = array();

			// loop trough the tree
			foreach ($result as $key => $value)
			{
				$tree[$value['_key_']] = str_repeat('&nbsp;', ($value['_level_']) * 3) . ($value['_level_'] ? '&raquo; ' : '') . $value[$field];
			}

			// return the result
			return $tree;
		}
		else
		{
			return false;
		}
	}

	// -----------------------------------------------------------------

	public function tree_dump_as_array(Array $attributes = array(), $skip_root = true)
	{
		return $this->_tree_dump_as('array', $attributes, $skip_root);
	}

	// -----------------------------------------------------------------

	public function tree_dump_as_html(Array $attributes = array(), $skip_root = true)
	{
		return $this->_tree_dump_as('html', $attributes, $skip_root);
	}

	// -----------------------------------------------------------------

	public function tree_dump_as_csv(Array $attributes = array(), $skip_root = true)
	{
		return $this->_tree_dump_as('csv', $attributes, $skip_root);
	}

	// -----------------------------------------------------------------

	public function tree_dump_as_tab(Array $attributes = array(), $skip_root = true)
	{
		return $this->_tree_dump_as('tab', $attributes, $skip_root);
	}

	/* -------------------------------------------------------------------------
	 * private class functions
	 * ---------------------------------------------------------------------- */

	private function tree_validate_model($object, $method)
	{
		if (get_class($object) !== get_class($this))
		{
			throw new \Exception('Model object passed to '.$method.'() is not an instance of '.get_class($this).'.');
		}
	}

	// -----------------------------------------------------------------

	/**
	 * Select a specific tree if the table contains multiple trees
	 *
	 * @param   mixed	type depends on the field type of the tree_field
	 * @return  object	this object, for chaining
	 */
	private function tree_get_tree_id()
	{
		// check if the current object is part of a tree
		if ( ! empty($this->{$this->configuration['tree_field']}))
		{
			return $this->{$this->configuration['tree_field']};
		}
		else
		{
			return $this->configuration['tree_value'];
		}
	}

	// -----------------------------------------------------------------

	/**
	 * dumps the tree with the object as root in different formats
	 *
	 * @param	string	type of output requested
	 * @param	array	list of columns to include in the dump
	 * @param	boolean	if true, the object itself (root of the dump) will not be included
	 * @return	mixed
	 */
	private function _tree_dump_as($type, $attributes, $skip_root)
	{
		if ($this->tree_is_valid($this))
		{
			$query = static::find();

			// if we have multiple roots
			if ( ! is_null($this->configuration['tree_field']))
			{
				$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
			}

			// create the where clause for this query
			if ($skip_root === true)
			{
				// select only all children
				$query->where($this->configuration['left_field'], '>', $this->{$this->configuration['left_field']});
				$query->where($this->configuration['right_field'], '<', $this->{$this->configuration['right_field']});
				$level = -1;
			}
			else
			{
				// select the node and all children
				$query->where($this->configuration['left_field'], '>=', $this->{$this->configuration['left_field']});
				$query->where($this->configuration['right_field'], '<=', $this->{$this->configuration['right_field']});
				$level = -2;
			}

			// fetch the result
			$result = $query->order_by($this->configuration['left_field'], 'ASC')->get();

			// create the start of the path
			$path = ( ! is_null($this->configuration['title_field'])) ? array($this->{$this->configuration['title_field']}) : array();

			// store the last left pointer
			$last_left = $this->{$this->configuration['left_field']};

			// parent key's
			$parents = array(-1 => null, 0 => $this->{static::$_primary_key[0]});

			// storage for the tree
			$tree = array();

			$previous_level = -1;
			$first_pointers = $last_pointers = array();

			// add level and path to the result
			foreach ( $result as $record )
			{
				// fetch the column data
				if ( empty($attributes))
				{
					$node = $record->to_array();
				}
				else
				{
					$node = array();
					foreach($record->to_array() as $name => $value)
					{
						if (in_array($name, $attributes) or in_array($name, $this->configuration) or $name == $this->{static::$_primary_key[0]})
						{
							$node[$name] = $value;
						}
					}
				}

				// store the primary key
				$node['_key_'] = $node[static::$_primary_key[0]];

				// calculate the nest level of this node
				$level += $last_left - $node[$this->configuration['left_field']] + 2;
				$last_left = $node[$this->configuration['left_field']];
				$node['_level_'] = $level;
				$node['_parent_'] = $parents[$level - 1];

				// update the parent keys
				$parents[$level] = $node[static::$_primary_key[0]];

				// track first and last level pointers
				$node['_first_'] = false;
				if ( ! isset($first_pointers[$node['_parent_']])) $first_pointers[$node['_parent_']] = $node['_key_'];
				$node['_last_'] = false;
				$last_pointers[$node['_parent_']] = $node['_key_'];

				// create the relative path to this node
				$node['_path_'] = '';
				if ( ! empty($this->configuration['title_field']) )
				{
					$path[$level] = $node[$this->configuration['title_field']];
					for ( $i = 0; $i <= $level; $i++ )
					{
						$node['_path_'] .= '/' . $path[$i];
					}
				}

				// store the node
				$tree[$node['_key_']] = $node;
			}
		}
		else
		{
			return false;
		}

		foreach($first_pointers as $pointer)
		{
			$tree[$pointer]['_first_'] = true;
		}
		foreach($last_pointers as $pointer)
		{
			$tree[$pointer]['_last_'] = true;
		}

		// convert the result to output if needed
		if ( in_array($type, array('tab', 'csv', 'html')) )
		{
			// storage for the result
			$convert = '';

			// loop through the nodes
			foreach ( $tree as $key => $value )
			{
				// prefix based on requested type
				switch ($type)
				{
					case 'tab';
						$convert .= str_repeat("\t", $value['_level_'] * 4 );
						break;
					case 'csv';
						break;
					case 'html';
						$convert .= str_repeat("&nbsp;", $value['_level_'] * 4 );
						break;
				}

				// print the attributes requested
				if ( ! empty($attributes) )
				{
					$att = reset($attributes);
					while($att){
						if ( is_numeric($value[$att]) )
						{
							$convert .= $value[$att];
						}
						else
						{
							$convert .= '"'.$value[$att].'"';
						}
						$att = next($attributes);
						if ($att)
						{
							$convert .= ($type == 'csv' ? "," : " ");
						}
					}
				}

				// postfix based on requested type
				switch ($type)
				{
					case 'tab';
						$convert .= "\n";
						break;
					case 'csv';
						$convert .= "\n";
						break;
					case 'html';
						$convert .= "<br />";
						break;
				}
			}
			return $convert;
		}
		else
		{
			return $tree;
		}



	}

	// -----------------------------------------------------------------

	private function _tree_shift_rlvalues($first, $delta)
	{
		$query = static::find();

		// if we have multiple roots
		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
		}

		// select the range
		$result = $query->where($this->configuration['left_field'], '>=', $first)->get();

		// update the left- and right pointers
		foreach($result as $key => $record)
		{
			$record->{$this->configuration['left_field']} += $delta;
			$record->{$this->configuration['right_field']} += $delta;

			// and save the record
			$record->save();
		}
	}

	// -----------------------------------------------------------------

	private function _tree_shift_rlrange($first, $last, $delta)
	{
		$query = static::find();

		// if we have multiple roots
		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->tree_get_tree_id());
		}

		// select the range
		$query->where($this->configuration['left_field'], '>=', $first);
		$query->where($this->configuration['right_field'], '<=', $last);
		$result = $query->get();

		// update the left- and right pointers
		foreach($result as $key => $record)
		{
			$record->{$this->configuration['left_field']} += $delta;
			$record->{$this->configuration['right_field']} += $delta;

			// and save the record
			$record->save();
		}
	}

	// -----------------------------------------------------------------

	private function _tree_move_subtree($destination_id)
	{
		// determine the size of the tree to move
		$treesize = $this->{$this->configuration['right_field']} - $this->{$this->configuration['left_field']} + 1;

		// get the objects left- and right pointers
		$left_id = $this->{$this->configuration['left_field']};
		$right_id = $this->{$this->configuration['right_field']};

		// shift to make some space
		$this->_tree_shift_rlvalues($destination_id, $treesize);

		// correct pointers if there were shifted to
		if ($this->{$this->configuration['left_field']} >= $destination_id)
		{
			$left_id += $treesize;
			$right_id += $treesize;
		}

		// enough room now, start the move
		$this->_tree_shift_rlrange($left_id, $right_id, $destination_id - $left_id);

		// and correct index values after the source
		$this->_tree_shift_rlvalues($right_id + 1, - $treesize);

		// return the moved object
		return $this;
	}
}

/* End of file model.php */
