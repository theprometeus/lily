<?php
    /**
     * Lily - Patcher class
     * 
     * @author Matheus Giovani <matheus@ad3com.com.br>
     * @since 1.0.0
     */

    namespace Lily;

    require_once __DIR__ . "/../vendor/autoload.php";

    class Patcher {
        static $instance;

        /**
         * Retrieves all built-in tasks
         *
         * @return array[string]
         */
        static function get_bultin_tasks() {
            // Find all task files
            $tasks = glob(__DIR__ . "/tasks/*.php");

            // Parses all task names
            foreach($tasks as &$task) {
                $task = str_replace(".php", "", basename($task));
                $task = "\\Lily\\Tasks\\" . Utils::snake_to_camel_case($task);
            }

            return $tasks;
        }

        /**
         * An array of registered tasks
         *
         * @var array[string=>Lily\Task]
         */
        private $registered_tasks = [];

        /**
         * An array of registered param names
         *
         * @var array[string]
         */
        private $registered_params = [];

        /**
         * The input directory with files to be processed
         *
         * @var string
         */
        private $input_dir = __DIR__;

        /**
         * The output directory that wil receive the patched files
         *
         * @var string
         */
        private $output_dir = __DIR__;

        /**
         * If can clear the output directory before running
         *
         * @var boolean
         */
        private $auto_clean = true;

        /**
         * An array of patches to be done
         *
         * @var array[\Lily\Patch]
         */
        private $patches = [];

        /**
         * An array of pending files to be saved
         *
         * @var array[string=>string]
         */
        private $pending_saves = [];

        /**
         * If can continue executing the patcher
         *
         * @var boolean
         */
        private $can_continue = true;

        /**
         * An array of valid options
         * 
         * @var array[string]
         */
        const VALID_OPTIONS = ["input_dir", "output_dir", "auto_clean"];

        public function __construct(array $options = []) {
            // Iterate over the default tasks
            foreach(static::get_bultin_tasks() as $task) {
                // Register it
                $this->register_task($task::NAME, $task);
            }

            // Iterate over all options
            foreach($options as $key => $value) {
                // Check if it's a valid option
                if (in_array($key, self::VALID_OPTIONS)) {
                    // Set the value
                    $this->{$key} = $value;
                }
            }

            self::$instance = $this;
        }

        /**
         * Creates or returns a single instance of the Lily patcher
         *
         * @return Patcher
         */
        static function instance() {
            if (self::$instance === null) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * Stops the patcher execution
         *
         * @return void
         */
        public function stop() {
            $this->can_continue = false;
        }

        /**
         * Retrieves a single task class
         *
         * @param string $task
         * @return \Lily\Task|null
         */
        public function get_task(string $task) {
            return $this->registered_tasks[$task] ?? null;
        }

        /**
         * Registers a single task
         *
         * @param string $name The task name
         * @param string|\Lily\Task $class The task class
         * @return void
         */
        public function register_task(string $name, string $class) {
            // Check if the task class exists
            if (!class_exists($class)) {
                throw new Error("Class {$class} doesn't exists.", "CLASS_NOT_EXISTS");
            }

            // Check if it's not a valid task
            if (!is_subclass_of($class, "\Lily\Task", true)) {
                throw new Error("Not a valid Lily task, Lily is sad. 😞", "INVALID_TASK");
            }

            // Register it
            $this->registered_tasks[$name] = $class;

            // Retrieve all registered params
            $this->registered_params = array_merge($this->registered_params, $class::REQUIRED_PARAMS, $class::OPTIONAL_PARAMS);

            // Remove duplicates
            $this->registered_params = array_unique($this->registered_params);

            // Join the parameters
            $registered_params = implode("|", $this->registered_params);

            // Update the comment regex
            \Lily\Patch::$comment_regex = "/\*.+?\@(?<param>lily|task|files|{$registered_params})(.+?(?<arguments>.+))?/m";
        }

        /**
         * Sets the patcher directory
         *
         * @param string $dir The input directory
         * @return string
         */
        public function set_input_directory(string $dir) {
            $this->input_dir = \Lily\Utils::path_resolve(rtrim($dir, "/") . "/");
            return $this->input_dir;
        }

        /**
         * Sets the patcher output directory
         *
         * @param string $dir The output directory
         * @return string
         */
        public function set_output_directory(string $dir) {
            $this->output_dir = \Lily\Utils::path_resolve(rtrim($dir, "/") . "/");
            return $this->output_dir;
        }

        /**
         * Registers a new patch
         *
         * @param string|Lily\Patch $patch
         * @return Lily\Patch
         */
        public function add_patch($patch) {
            // Check if it's not a string and it's not a patch
            if (!is_string($patch) && !($patch instanceof Patch)) {
                throw new Error("Invalid patch given: not a valid string and not a Patch instance.", "INVALID_PATCH");
            }

            // Check if it's a string
            if (is_string($patch)) {
                // Parse it as a patch file
                $patch = Patch::from_file($patch);

                // Check if it's not a valid patch file
                if (!$patch) {
                    return false;
                }
            }

            $this->patches[] = $patch;
            return $patch;
        }

        /**
         * Generates current execution results
         *
         * @return object
         */
        public function generate_results() {
            $results = (object) [
                "succeeded" => [],
                "failed" => [],
                "not_executed" => []
            ];

            // Iterate over all patches
            foreach($this->patches as $patch) {
                // Iterate over all patch tasks
                foreach($patch->get_tasks() as $task) {
                    // Check for this task status
                    switch($task->get_status()) {
                        // Case not executed
                        case $task::STATUS_NOT_EXECUTED:
                            // Add it to the not executed ones
                            $results->not_executed[] = &$task;
                        break;

                        // Case succeded
                        case $task::STATUS_SUCCEEDED:
                            // Add it to the succeeded ones
                            $results->succeeded[] = &$task;
                        break;

                        // Case failed
                        case $task::STATUS_FAILED:
                            // Add it to the failed ones
                            $results->failed[] = &$task;
                        break;
                    }
                }
            }

            return $results;
        }

        /**
         * Run the patcher, applying all to a string
         * 
         * @param string $content The content to be processed
         * @return boolean
         */
        public function apply(string $content) {
            // Create the file handler to be processed
            $file = new File(null, $content);

            // Iterate over all patches
            foreach($this->patches as $patch) {
                // Log it it needed
                \Lily\Console::log("processing patch", $patch->name);

                // Iterate over all patch tasks
                foreach($patch->get_tasks() as $task) {
                    // Log it it needed
                    \Lily\Console::log("doing task", $task->get_name());

                    // Apply the task patch to it
                    $result = $task->apply($file);

                    // Check if failed to apply the task
                    if (!$result) {
                        \Lily\Console::error("an error ocurred while doing task", $task->get_name());
                        return false;
                    }
                }
            }

            return $file->get_modified_content();
        }

        /**
         * Run the patcher, applying all patches
         *
         * @return boolean
         */
        public function run() {
            /**
             * The patcher files
             * 
             * @var array[string]
             */
            $patcher_files = array_map(function($file) {
                return \Lily\Utils::path_resolve($file);
            }, \Lily\Utils::directory_as_array($this->input_dir));

            // Check if needs to clear the output directory before starting
            if ($this->auto_clean) {
                // Check if the directory exists
                if (is_dir($this->output_dir)) {
                    // Clean it
                    \Lily\Utils::rrmdir($this->output_dir, true);

                    // Wait for the system to unlock the directory
                    sleep(1);
                } else {
                    // Try creating the output directory
                    if (!mkdir($this->output_dir)) {
                        // Skip the operation
                        throw new Error("An exception ocurred while trying to create the output directory: " . error_get_last()["message"], error_get_last()["code"]);
                    }
                }
            }

            // Iterate over all patches
            foreach($this->patches as $patch) {
                // Log it it needed
                \Lily\Console::log("processing patch", $patch->name);

                // Iterate over all patch tasks
                foreach($patch->get_tasks() as $task) {
                    // Log it it needed
                    \Lily\Console::log("doing task", $task->get_name());

                    $files = null;

                    // Check if this task has any specific file
                    if ($task->has_files()) {
                        // Set the iterating files to the task files
                        $files = array_map(function($file) {
                            return \Lily\Utils::path_resolve($this->input_dir, $file);
                        }, $task->get_files());
                    } else {
                        // Set to all files
                        $files = $patcher_files;
                    }

                    // Iterate over all files
                    foreach($files as $file) {
                        // Get the output file name
                        $output_file = str_replace($this->input_dir, $this->output_dir, $file);

                        // Check if file is not loaded
                        if (empty($this->pending_saves[$output_file])) {
                            // Load it
                            $this->pending_saves[$output_file] = new File($file);
                        }

                        // Apply the task patch to it
                        $result = $task->apply($this->pending_saves[$output_file]);

                        // Check if failed to apply the task
                        if (!$result) {
                            \Lily\Console::error("An error ocurred while doing task", $task->get_name());
                            return false;
                        }

                        // Check if the execution was stopped
                        if (!$this->can_continue) {
                            // Break the execution
                            return false;
                        }
                    }
                }
            }

            // Clear the patcher files
            $patcher_files = null;

            // Save all pending files
            foreach($this->pending_saves as $file) {
                // Save it
                $file->save(str_replace($this->input_dir, $this->output_dir, $file->name));
            }

            // Clear the pending saves
            $this->pending_saves = [];
        }
    }