const $ = window.jQuery;
const { __, _x, _n, _nx } = wp.i18n;

/**
 * RediPress JavaScript admin functionalities
 */
class RediPressAdmin {

    /**
     * Plugin constructor
     */
    constructor() {
        this.events();
    }

    /**
     * Events registering method
     *
     * @memberof RediPressAdmin
     */
    events() {

        // Create the index button
        $( document ).on( 'click', '#redipress_index', ( e ) => {
            e.preventDefault();

            $( '#redipress_index_info' ).html( __( 'Creating index...', 'redipress' ) );

            dp( 'redipress_create_index', {
                url: RediPress.homeUrl,
                success: ( data ) => {
                    switch ( data ) {
                        case true:
                            $( '#redipress_index_info' ).html( __( 'Index created.', 'redipress' ) );
                            break;
                        case 'Index already exists. Drop it first!':
                            $( '#redipress_index_info' ).html( __( 'Index already exists.', 'redipress' ) );
                            break;
                        default:
                            $( '#redipress_drop_index_info' ).html( __( 'Unprecetended response: ', 'redipress' ) + data );
                            break;
                    }
                }
            });
        });

        // Delete the index button
        $( document ).on( 'click', '#redipress_drop_index', ( e ) => {
            e.preventDefault();

            if ( confirm( __( 'This will delete the whole index. Are you sure?', 'redipress' ) ) ) {
                $( '#redipress_drop_index_info' ).html( __( 'Deleting index...', 'redipress' ) );

                dp( 'redipress_drop_index', {
                    url: RediPress.homeUrl,
                    success: ( data ) => {
                        switch ( data ) {
                            case true:
                                $( '#redipress_drop_index_info' ).html( __( 'Index deleted.', 'redipress' ) );
                                break;
                            case 'Index already exists. Drop it first!':
                                $( '#redipress_drop_index_info' ).html( __( 'There were no index to delete or it was created under another name.', 'redipress' ) );
                                break;
                            default:
                                $( '#redipress_drop_index_info' ).html( __( 'Unprecetended response: ', 'redipress' ) + data );
                                break;
                        }
                    }
                });
            }
        });

        // Index all button
        $( document ).on( 'click', '#redipress_index_all', ( e ) => {
            e.preventDefault();

            $( '#redipress_index_all_info' ).html( __( 'Indexing...', 'redipress' ) );

            dp( 'redipress_index_all', {
                url: RediPress.homeUrl,
                success: ( data ) => {
                    $( '#redipress_index_all_info' ).html( __( 'Index created.', 'redipress' ) );
                    console.log( data );
                }
            });
        });
    }
}

$( document ).ready( function() {
    new RediPressAdmin();
});
