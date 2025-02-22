import 'core-js/fn/promise'; // Promise polyfill
import 'whatwg-fetch'; // fetch polyfill

const $ = window.jQuery;
const { __, _x, _n, _nx } = wp.i18n;

/**
 * RediPress JavaScript admin functionalities
 */
class RediPressAdmin {

    /**
     * Cached elements
     *
     * @type {Object}
     */
    cached = {};

    /**
     * Plugin constructor
     */
    constructor() {
        this.cache();
        this.events();
    }

    /**
     * Cache relevant elements
     */
    cache() {
        this.cached.$redipress_index_progress        = $( '#redipress_index_progress' );
        this.cached.$redipress_index_info            = $( '#redipress_index_info' );
        this.cached.$redipress_index                 = $( '#redipress_index' );
        this.cached.$redipress_drop_index            = $( '#redipress_drop_index' );
        this.cached.$redipress_index_all             = $( '#redipress_index_all' );
        this.cached.$redipress_current_index         = $( '#redipress_current_index' );
        this.cached.$redipress_index_count_delimiter = $( '#redipress_index_count_delimiter' );
        this.cached.$redipress_max_index             = $( '#redipress_max_index' );
    }

    /**
     * Events registering method
     *
     * @memberof RediPressAdmin
     */
    events() {

        // Create the index button
        $( this.cached.$redipress_index ).on( 'click', ( e ) => this.redipress_index( e ) );

        // Delete the index button
        $( this.cached.$redipress_drop_index ).on( 'click', ( e ) => this.redipress_drop_index( e ) );

        // Index all button
        $( this.cached.$redipress_index_all ).on( 'click', ( e ) => this.redipress_index_all( e ) );
    }

    /**
     * Create index
     *
     * @param {Object} e Click event.
     */
    redipress_index( e ) {
        e.preventDefault();

        this.cached.$redipress_index_info.text( __( 'Creating index...', 'redipress' ) );

        this.restApiCall( '/create_index', 'POST' ).then( ( data ) => {
            switch ( data ) {
                case true:
                    this.cached.$redipress_index_info.text( __( 'Index created.', 'redipress' ) );
                    break;
                case 'Index already exists. Drop it first!':
                    this.cached.$redipress_index_info.text( __( 'Index already exists.', 'redipress' ) );
                    break;
                default:
                    this.cached.$redipress_index_info.text( __( 'Unprecedented response: ', 'redipress' ) + data );
                    break;
            }
        }).catch( ( error ) => this.errorHandler( error ) );
    }

    /**
     * Drop the index.
     *
     * @param {Object} e Click event.
     */
    redipress_drop_index( e ) {
        e.preventDefault();

        if ( confirm( __( 'This will delete the whole index. Are you sure?', 'redipress' ) ) ) {
            this.cached.$redipress_index_info.text( __( 'Deleting index...', 'redipress' ) );

            this.restApiCall( '/drop_index', 'DELETE' ).then( ( data ) => {
                switch ( data ) {
                    case true:
                        this.cached.$redipress_index_info.text( __( 'Index deleted.', 'redipress' ) );
                        this.cached.$redipress_index_progress.prop( 'value', 0 );
                        this.cached.$redipress_current_index.text( 0 );
                        break;
                    case 'Unknown Index name':
                        this.cached.$redipress_index_info.text( __( 'There were no index to delete or it was created under another name.', 'redipress' ) );
                        break;
                    default:
                        this.cached.$redipress_index_info.text( __( 'Unprecedented response: ', 'redipress' ) + data );
                        break;
                }
            }).catch( ( error ) => this.errorHandler( error ) );
        }
    }

    /**
     * Index all items in the database
     *
     * @param {Object} e Click event.
     */
    redipress_index_all( e ) {
        e.preventDefault();

        if ( confirm( __( 'This can take a while. Are you sure?', 'redipress' ) ) ) {
            this.cached.$redipress_index_info.text( __( 'Indexing...', 'redipress' ) );
            const offset = this.cached.$redipress_index_progress.val();
            const formData = new FormData();
            formData.set( 'offset', offset );

            this.restApiCall( '/schedule_index_all', 'POST', formData ).then( () => {
                this.cached.$redipress_index_info.text( __( 'Indexing started...', 'redipress' ) );
            }).catch( ( error ) => this.errorHandler( error ) );
        }
    }

    /**
     * Common error handler for ajax requests
     *
     * @param {String} error Error message from server.
     */
    errorHandler( error ) {
        this.cached.$redipress_index_info.text( __( 'Unprecedented response: ', 'redipress' ) + error );
    }

    /**
     * Make a rest api call
     *
     * @param  {String}  url    Url to add after namespace.
     * @param  {String}  method Method to use for the call.
     * @param  {FormData|null} body   Formdata to send or null.
     * @return {Promise}        A fetch ajax call promise.
     */
    restApiCall( url, method, body = null  ) {
        return fetch( RediPress.restUrl + url, {
            method,
            body,
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-WP-Nonce': RediPress.restApiNonce
            }
        }).then( ( response ) => {
            if ( ! response.ok ) {
                throw Error( response.statusText );
            }

            return response;
        }).then( res => res.json() );
    }
}

$( () => new RediPressAdmin() );
