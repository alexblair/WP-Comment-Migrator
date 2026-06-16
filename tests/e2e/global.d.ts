export {};

declare global {
  interface Window {
    ajaxurl: string;
    cmtAdmin: {
      ajax_url: string;
      nonce: string;
      confirm_migrate: string;
      cancel: string;
      confirm_rollback: string;
      select_target: string;
      select_comments: string;
    };
  }
}
