<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HWT_Logger {

    private $log_file = '';

    /**
     * Start a new log session.
     *
     * @return string Path to the log file.
     */
    public function start_session() {
        $this->log_file = HWT_PIM_LOG_DIR . '/import-' . gmdate( 'Y-m-d-His' ) . '.log';
        return $this->log_file;
    }

    /**
     * Set an existing log file path (for resuming).
     */
    public function set_log_file( $path ) {
        $this->log_file = $path;
    }

    /**
     * Get the current log file path.
     */
    public function get_log_path() {
        return $this->log_file;
    }

    public function info( $message ) {
        $this->write( 'INFO', $message );
    }

    public function warn( $message ) {
        $this->write( 'WARN', $message );
    }

    public function error( $message ) {
        $this->write( 'ERROR', $message );
    }

    private function write( $level, $message ) {
        if ( empty( $this->log_file ) ) {
            $this->start_session();
        }

        $line = sprintf(
            "[%s] %-5s %s\n",
            gmdate( 'Y-m-d H:i:s' ),
            $level,
            $message
        );

        file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
    }
}
